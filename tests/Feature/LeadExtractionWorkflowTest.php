<?php

namespace Tests\Feature;

use App\Enums\ExtractionStatus;
use App\Enums\LeadStage;
use App\Models\Lead;
use App\Services\DocumentPreprocessService;
use App\Services\DocumentService;
use App\Services\ExtractionService;
use App\Services\GeminiExtractionService;
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
        config()->set('services.gemini.api_key', 'test-key');

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

        $originalPayload = Storage::disk('public')->get($document->storage_path);
        $originalBase64 = base64_encode($originalPayload);
        $callCount = 0;

        $mock = Mockery::mock(GeminiExtractionService::class);
        $mock->shouldReceive('extract')
            ->twice()
            ->withArgs(function (?string $mimeType, string $payload) use (&$callCount, $originalBase64): bool {
                $callCount++;

                if ($callCount === 1) {
                    return $mimeType === 'image/jpeg'
                        && strlen($payload) < strlen($originalBase64);
                }

                return $mimeType === 'image/jpeg'
                    && $payload === $originalBase64;
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

        $this->app->instance(GeminiExtractionService::class, $mock);

        $record = app(ExtractionService::class)->extract($document->fresh());

        $this->assertSame(ExtractionStatus::COMPLETED, $record->extraction_status);
        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'ic_number' => '900101101234',
        ]);

        $metadata = $document->fresh()->metadata;

        $this->assertTrue((bool) data_get($metadata, 'document_preprocess.applied'));
        $this->assertTrue((bool) data_get($metadata, 'extraction_pipeline.fallback.triggered'));
        $this->assertSame('needs_review', data_get($metadata, 'extraction_pipeline.fallback.reason'));
        $this->assertSame('original', data_get($metadata, 'extraction_pipeline.input_source'));
        $this->assertCount(2, data_get($metadata, 'extraction_pipeline.attempts', []));
    }

    public function test_document_preprocessing_skips_pdf_and_preserves_original_file(): void
    {
        Storage::fake('public');

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

        $before = Storage::disk('public')->get($document->storage_path);
        $prepared = app(DocumentPreprocessService::class)->prepare($document);
        $after = Storage::disk('public')->get($document->storage_path);

        $this->assertNull($prepared['optimized']);
        $this->assertSame('unsupported_mime_type', data_get($prepared, 'metadata.skipped_reason'));
        $this->assertSame($before, $after);
        $this->assertSame($before, $prepared['original']['payload']);
    }
}
