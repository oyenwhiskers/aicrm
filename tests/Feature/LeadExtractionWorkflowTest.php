<?php

namespace Tests\Feature;

use App\Contracts\DocumentIntelligenceServiceInterface;
use App\Enums\ExtractionStatus;
use App\Enums\LeadStage;
use App\Models\Lead;
use App\Services\DocumentService;
use App\Services\ExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class LeadExtractionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_document_upload_for_async_processing(): void
    {
        Storage::fake('public');

        $lead = Lead::query()->create([
            'name' => 'Async Lead',
            'phone_number' => '+60121234567',
            'stage' => LeadStage::DOC_REQUESTED,
        ]);

        $lead->profile()->create();

        $response = $this->postJson("/api/leads/{$lead->id}/documents", [
            'document_type' => 'ic',
            'file' => UploadedFile::fake()->image('ic.jpg'),
        ]);

        $response->assertAccepted()
            ->assertJsonPath('data.document.upload_status', 'queued');

        $this->assertDatabaseHas('lead_documents', [
            'lead_id' => $lead->id,
            'document_type' => 'ic',
            'upload_status' => 'queued',
        ]);
    }

    public function test_it_retries_with_original_when_optimized_image_needs_review(): void
    {
        Storage::fake('public');
        config()->set('services.document_intelligence.shared_storage.enabled_disks', ['public']);
        config()->set('services.document_intelligence.shared_storage.disk_roots.public', storage_path('framework/testing/disks/public'));

        $lead = Lead::query()->create([
            'name' => 'Retry Lead',
            'phone_number' => '+60129876543',
            'stage' => LeadStage::DOC_REQUESTED,
        ]);

        $lead->profile()->create();

        $document = app(DocumentService::class)->storeAndRegister(
            $lead,
            UploadedFile::fake()->image('ic-large.jpg', 3200, 2400)->size(6000),
            'ic',
            null,
        );

        $callCount = 0;

        $mock = Mockery::mock(DocumentIntelligenceServiceInterface::class);
        $mock->shouldReceive('isConfigured')
            ->once()
            ->andReturn(true);
        $mock->shouldReceive('extractDocument')
            ->twice()
            ->withArgs(function (array $payload) use (&$callCount, $document): bool {
                $callCount++;

                if ($callCount === 1) {
                    return ($payload['mime_type'] ?? null) === 'image/jpeg'
                        && ($payload['storage_disk'] ?? null) === 'public'
                        && ($payload['storage_path'] ?? null) === $document->storage_path
                        && ($payload['payload'] ?? null) === null
                        && ($payload['mode'] ?? null) === 'primary';
                }

                return ($payload['mime_type'] ?? null) === 'image/jpeg'
                    && ($payload['storage_disk'] ?? null) === 'public'
                    && ($payload['storage_path'] ?? null) === $document->storage_path
                    && ($payload['payload'] ?? null) === null
                    && ($payload['mode'] ?? null) === 'fallback_original';
            })
            ->andReturn(
                [
                    'summary' => 'Image needs review.',
                    'confidence' => 'low',
                    'needs_review' => true,
                    'classification' => [
                        'document_type' => 'other',
                    ],
                    'fields' => [],
                    'raw_text' => null,
                ],
                [
                    'summary' => 'IC extracted successfully.',
                    'confidence' => 'high',
                    'needs_review' => false,
                    'classification' => [
                        'document_type' => 'ic',
                        'ic_side' => 'front',
                    ],
                    'fields' => [
                        'full_name' => 'Jane Doe',
                        'ic_number' => '900101101234',
                        'date_of_birth' => '1990-01-01',
                    ],
                    'raw_text' => 'Jane Doe 900101101234',
                ],
            );

        $this->app->instance(DocumentIntelligenceServiceInterface::class, $mock);

        $record = app(ExtractionService::class)->extract($document->fresh());

        $this->assertSame(ExtractionStatus::COMPLETED, $record->extraction_status);
        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'ic_number' => '900101101234',
        ]);

        $metadata = $document->fresh()->metadata;

        $this->assertSame('shared_storage', data_get($metadata, 'document_processing_handoff.type'));
        $this->assertSame('public', data_get($metadata, 'document_processing_handoff.storage_disk'));
        $this->assertTrue((bool) data_get($metadata, 'extraction_pipeline.fallback.triggered'));
        $this->assertSame('needs_review', data_get($metadata, 'extraction_pipeline.fallback.reason'));
        $this->assertSame('shared_storage', data_get($metadata, 'extraction_pipeline.input_source'));
        $this->assertCount(2, data_get($metadata, 'extraction_pipeline.attempts', []));
    }

    public function test_python_shared_storage_failure_is_marked_for_review_without_worker_crash(): void
    {
        Storage::fake('public');
        config()->set('services.document_intelligence.shared_storage.enabled_disks', ['public']);
        config()->set('services.document_intelligence.shared_storage.disk_roots.public', storage_path('framework/testing/disks/public'));

        $lead = Lead::query()->create([
            'name' => 'Pdf Lead',
            'phone_number' => '+60127770000',
            'stage' => LeadStage::DOC_REQUESTED,
        ]);

        $lead->profile()->create();

        $document = app(DocumentService::class)->storeAndRegister(
            $lead,
            UploadedFile::fake()->create('payslip.pdf', 1024, 'application/pdf'),
            'payslip',
            null,
        );

        $mock = Mockery::mock(DocumentIntelligenceServiceInterface::class);
        $mock->shouldReceive('isConfigured')
            ->once()
            ->andReturn(true);
        $mock->shouldReceive('extractDocument')
            ->once()
            ->andReturn([
                'summary' => 'Local document processing failed: shared storage file could not be opened.',
                'confidence' => 'low',
                'needs_review' => true,
                'review_reasons' => ['shared_storage_unavailable'],
                'classification' => [
                    'document_type' => 'other',
                ],
                'fields' => [],
                'raw_text' => null,
                'provider_meta' => [
                    'provider' => 'python_local',
                    'input_source' => 'shared_storage',
                    'technical_failure' => true,
                ],
            ]);

        $this->app->instance(DocumentIntelligenceServiceInterface::class, $mock);

        $record = app(ExtractionService::class)->extract($document->fresh());

        $this->assertSame(ExtractionStatus::REVIEW_REQUIRED, $record->extraction_status);
        $this->assertContains('shared_storage_unavailable', data_get($document->fresh()->metadata, 'classification.review_reasons', []));
    }

    public function test_laravel_marks_contradictory_ic_result_for_review_without_reclassifying(): void
    {
        Storage::fake('public');

        $lead = Lead::query()->create([
            'name' => 'Guard Lead',
            'phone_number' => '+60128880000',
            'stage' => LeadStage::DOC_REQUESTED,
        ]);

        $lead->profile()->create();

        $document = app(DocumentService::class)->storeAndRegister(
            $lead,
            UploadedFile::fake()->create('payslip.pdf', 512, 'application/pdf'),
            'other',
            null,
        );

        $mock = Mockery::mock(DocumentIntelligenceServiceInterface::class);
        $mock->shouldReceive('isConfigured')
            ->once()
            ->andReturn(true);
        $mock->shouldReceive('extractDocument')
            ->once()
            ->andReturn([
                'summary' => 'Detected Malaysian IC document with front classification at medium confidence.',
                'confidence' => 'medium',
                'needs_review' => false,
                'review_reasons' => [],
                'classification' => [
                    'document_type' => 'ic',
                    'ic_side' => 'front',
                    'statement_year' => 2026,
                    'statement_month' => 4,
                    'statement_period' => '2026-04',
                ],
                'fields' => [
                    'full_name' => 'Jane Doe',
                    'ic_number' => '900101101234',
                    'employer' => 'Example Employer',
                    'net_pay' => 2800.00,
                ],
                'raw_text' => 'KAD PENGENALAN salary gaji net pay 2800 april 2026',
            ]);

        $this->app->instance(DocumentIntelligenceServiceInterface::class, $mock);

        $record = app(ExtractionService::class)->extract($document->fresh());

        $this->assertSame(ExtractionStatus::REVIEW_REQUIRED, $record->extraction_status);

        $metadata = $document->fresh()->metadata;

        $this->assertSame('ic', data_get($metadata, 'classification.document_type'));
        $this->assertTrue((bool) data_get($metadata, 'classification.needs_review'));
        $this->assertContains('contradictory_ic_statement_period', data_get($metadata, 'classification.review_reasons', []));
        $this->assertContains('contradictory_ic_payroll_evidence', data_get($metadata, 'classification.review_reasons', []));
        $this->assertNull($lead->fresh()->ic_number);
    }
}
