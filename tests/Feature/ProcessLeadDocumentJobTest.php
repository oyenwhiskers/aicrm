<?php

namespace Tests\Feature;

use App\Contracts\DocumentIntelligenceServiceInterface;
use App\Enums\ExtractionStatus;
use App\Enums\LeadStage;
use App\Models\Lead;
use App\Services\ActivityLogService;
use App\Services\DocumentService;
use App\Services\ExtractionService;
use App\Services\GeminiConcurrencyService;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use App\Jobs\ProcessLeadDocumentJob;

class ProcessLeadDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requeues_when_no_shared_gemini_slot_is_available(): void
    {
        Storage::fake('public');

        $document = $this->createQueuedDocument();
        $job = new ProcessLeadDocumentJob($document->id);
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('release')->once()->with(7);
        $job->setJob($queueJob);

        $geminiConcurrency = Mockery::mock(GeminiConcurrencyService::class);
        $geminiConcurrency->shouldReceive('acquireGlobal')->once()->andReturn(null);
        $geminiConcurrency->shouldReceive('slotRequeueDelaySeconds')->once()->andReturn(7);

        $documentIntelligence = Mockery::mock(DocumentIntelligenceServiceInterface::class);
        $documentIntelligence->shouldReceive('requiresSharedGeminiSlot')->once()->andReturn(true);

        $extractionService = Mockery::mock(ExtractionService::class);
        $extractionService->shouldNotReceive('extract');

        $job->handle(
            $extractionService,
            app(ActivityLogService::class),
            $geminiConcurrency,
            $documentIntelligence,
        );

        $metadata = $document->fresh()->metadata;

        $this->assertSame('queued', $document->fresh()->upload_status->value);
        $this->assertSame(1, data_get($metadata, 'extraction_pipeline.gemini_slot_requeues'));
    }

    public function test_it_releases_shared_slot_after_successful_processing(): void
    {
        Storage::fake('public');

        $document = $this->createQueuedDocument();
        $job = new ProcessLeadDocumentJob($document->id);
        $lease = new \stdClass();

        $geminiConcurrency = Mockery::mock(GeminiConcurrencyService::class);
        $geminiConcurrency->shouldReceive('acquireGlobal')->once()->andReturn($lease);
        $geminiConcurrency->shouldReceive('release')->once()->with($lease);

        $documentIntelligence = Mockery::mock(DocumentIntelligenceServiceInterface::class);
        $documentIntelligence->shouldReceive('requiresSharedGeminiSlot')->once()->andReturn(true);

        $extractionService = Mockery::mock(ExtractionService::class);
        $result = new class {
            public ExtractionStatus $extraction_status;

            public function __construct()
            {
                $this->extraction_status = ExtractionStatus::COMPLETED;
            }
        };
        $extractionService->shouldReceive('extract')->once()->andReturn($result);

        $job->handle(
            $extractionService,
            app(ActivityLogService::class),
            $geminiConcurrency,
            $documentIntelligence,
        );

        $this->assertSame('uploaded', $document->fresh()->upload_status->value);
    }

    public function test_it_releases_shared_slot_after_failed_processing(): void
    {
        Storage::fake('public');

        $document = $this->createQueuedDocument();
        $job = new ProcessLeadDocumentJob($document->id);
        $lease = new \stdClass();

        $geminiConcurrency = Mockery::mock(GeminiConcurrencyService::class);
        $geminiConcurrency->shouldReceive('acquireGlobal')->once()->andReturn($lease);
        $geminiConcurrency->shouldReceive('release')->once()->with($lease);

        $documentIntelligence = Mockery::mock(DocumentIntelligenceServiceInterface::class);
        $documentIntelligence->shouldReceive('requiresSharedGeminiSlot')->once()->andReturn(true);

        $extractionService = Mockery::mock(ExtractionService::class);
        $extractionService->shouldReceive('extract')->once()->andThrow(new \RuntimeException('Gemini failed.'));

        $job->handle(
            $extractionService,
            app(ActivityLogService::class),
            $geminiConcurrency,
            $documentIntelligence,
        );

        $this->assertSame('failed', $document->fresh()->upload_status->value);
    }

    public function test_it_marks_document_failed_when_worker_exhausts_attempts(): void
    {
        Storage::fake('public');

        $document = $this->createQueuedDocument();
        $job = new ProcessLeadDocumentJob($document->id);

        $job->failed(new \RuntimeException('Gemini capacity wait window expired.'));

        $document = $document->fresh();

        $this->assertSame('failed', $document->upload_status->value);
        $this->assertSame(
            'Gemini capacity wait window expired.',
            data_get($document->metadata, 'extraction_pipeline.processing_error')
        );
        $this->assertTrue((bool) data_get($document->metadata, 'extraction_pipeline.failed_by_worker'));
    }

    public function test_python_provider_processes_without_shared_gemini_slot(): void
    {
        Storage::fake('public');
        config()->set('services.document_intelligence.provider', 'python');

        $document = $this->createQueuedDocument();
        $job = new ProcessLeadDocumentJob($document->id);

        $geminiConcurrency = Mockery::mock(GeminiConcurrencyService::class);
        $geminiConcurrency->shouldNotReceive('acquireGlobal');
        $geminiConcurrency->shouldNotReceive('release');

        $documentIntelligence = Mockery::mock(DocumentIntelligenceServiceInterface::class);
        $documentIntelligence->shouldReceive('requiresSharedGeminiSlot')->once()->andReturn(false);

        $extractionService = Mockery::mock(ExtractionService::class);
        $result = new class {
            public ExtractionStatus $extraction_status;

            public function __construct()
            {
                $this->extraction_status = ExtractionStatus::REVIEW_REQUIRED;
            }
        };
        $extractionService->shouldReceive('extract')->once()->andReturn($result);

        $job->handle(
            $extractionService,
            app(ActivityLogService::class),
            $geminiConcurrency,
            $documentIntelligence,
        );

        $document = $document->fresh();

        $this->assertSame('uploaded', $document->upload_status->value);
        $this->assertSame('python', data_get($document->metadata, 'extraction_pipeline.provider'));
    }

    protected function createQueuedDocument()
    {
        $lead = Lead::query()->create([
            'name' => 'Job Lead',
            'phone_number' => '+60125550000',
            'stage' => LeadStage::DOC_REQUESTED,
        ]);

        $lead->profile()->create();

        return app(DocumentService::class)->storeAndRegister(
            $lead,
            UploadedFile::fake()->image('queued.jpg', 1200, 800),
            'ic',
            null,
        );
    }
}
