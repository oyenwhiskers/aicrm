<?php

namespace App\Jobs;

use App\Contracts\DocumentIntelligenceServiceInterface;
use App\Enums\ExtractionStatus;
use App\Enums\UploadStatus;
use App\Models\LeadDocument;
use App\Services\ActivityLogService;
use App\Services\ExtractionService;
use App\Services\GeminiConcurrencyService;
use Carbon\Carbon;
use Throwable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ProcessLeadDocumentJob implements ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Gemini backpressure requeues consume attempts in Laravel, so allow a
     * generous retry budget and bound it by retryUntil() below.
     */
    public int $tries = 600;

    public function __construct(
        public int $documentId,
    ) {
        $this->onQueue(config('queue.workloads.documents', 'documents'));
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(
        ExtractionService $extractionService,
        ActivityLogService $activityLogService,
        GeminiConcurrencyService $geminiConcurrencyService,
        DocumentIntelligenceServiceInterface $documentIntelligenceService,
    ): void
    {
        $document = LeadDocument::query()
            ->with('lead.profile')
            ->find($this->documentId);

        if (! $document || $document->upload_status === UploadStatus::DELETING) {
            return;
        }

        $provider = config('services.document_intelligence.provider', 'gemini');
        $geminiLease = null;

        if ($documentIntelligenceService->requiresSharedGeminiSlot()) {
            $geminiLease = $geminiConcurrencyService->acquireGlobal();

            if (! $geminiLease) {
                $metadata = $this->markSlotRequeue($document, $provider);
                $delaySeconds = $geminiConcurrencyService->slotRequeueDelaySeconds();

                Log::info('document_gemini_slot_unavailable', [
                    'event' => 'document_gemini_slot_unavailable',
                    'document_id' => $document->id,
                    'lead_id' => $document->lead_id,
                    'provider' => $provider,
                    'requeue_count' => data_get($metadata, 'extraction_pipeline.gemini_slot_requeues', 0),
                    'delay_seconds' => $delaySeconds,
                ]);

                $this->release($delaySeconds);

                return;
            }
        }

        $metadata = $this->markProcessingStarted($document, $provider, $geminiLease !== null);

        Log::info($geminiLease ? 'document_gemini_slot_acquired' : 'document_processing_started', [
            'event' => $geminiLease ? 'document_gemini_slot_acquired' : 'document_processing_started',
            'document_id' => $document->id,
            'lead_id' => $document->lead_id,
            'provider' => $provider,
            'queue_wait_seconds' => data_get($metadata, 'extraction_pipeline.queue_wait_seconds'),
        ]);

        try {
            $extraction = $extractionService->extract($document);

            $metadata = $document->fresh()->metadata ?? [];
            $metadata['processed_at'] = now()->toIso8601String();
            unset($metadata['processing_error']);

            $document->forceFill([
                'upload_status' => $extraction->extraction_status === ExtractionStatus::FAILED
                    ? UploadStatus::FAILED
                    : UploadStatus::UPLOADED,
                'metadata' => $metadata,
            ])->save();

            $activityLogService->log(
                $document->lead,
                'document.processing_completed',
                'Document background processing completed.',
                [
                    'document_id' => $document->id,
                    'upload_status' => $document->upload_status->value,
                    'extraction_status' => $extraction->extraction_status->value,
                ],
            );
        } catch (\Throwable $exception) {
            $metadata = $document->fresh()->metadata ?? [];
            $metadata['processed_at'] = now()->toIso8601String();
            $metadata['processing_error'] = $exception->getMessage();
            data_set($metadata, 'extraction_pipeline.processing_error', $exception->getMessage());
            data_set($metadata, 'extraction_pipeline.extraction_finished_at', now()->toIso8601String());

            $document->forceFill([
                'upload_status' => UploadStatus::FAILED,
                'metadata' => $metadata,
            ])->save();

            $activityLogService->log(
                $document->lead,
                'document.processing_failed',
                'Document background processing failed.',
                [
                    'document_id' => $document->id,
                    'error' => $exception->getMessage(),
                ],
            );
        } finally {
            if ($geminiLease) {
                $geminiConcurrencyService->release($geminiLease);
            }
            RefreshLeadDocumentStateJob::dispatch($document->lead_id);
        }
    }

    public function failed(Throwable $exception): void
    {
        $document = LeadDocument::query()
            ->with('lead')
            ->find($this->documentId);

        if (! $document || $document->upload_status === UploadStatus::DELETING) {
            return;
        }

        $metadata = $document->metadata ?? [];
        $failureTimestamp = now()->toIso8601String();

        $metadata['processed_at'] = $failureTimestamp;
        $metadata['processing_error'] = $exception->getMessage();
        data_set($metadata, 'extraction_pipeline.processing_error', $exception->getMessage());
        data_set($metadata, 'extraction_pipeline.extraction_finished_at', $failureTimestamp);
        data_set($metadata, 'extraction_pipeline.failed_by_worker', true);
        data_set($metadata, 'extraction_pipeline.failed_at', $failureTimestamp);

        $document->forceFill([
            'upload_status' => UploadStatus::FAILED,
            'metadata' => $metadata,
        ])->save();

        app(ActivityLogService::class)->log(
            $document->lead,
            'document.processing_failed',
            'Document background processing failed.',
            [
                'document_id' => $document->id,
                'error' => $exception->getMessage(),
                'failed_by_worker' => true,
            ],
        );

        Log::error('document_processing_failed_by_worker', [
            'event' => 'document_processing_failed_by_worker',
            'document_id' => $document->id,
            'lead_id' => $document->lead_id,
            'error' => $exception->getMessage(),
        ]);

        RefreshLeadDocumentStateJob::dispatch($document->lead_id);
    }

    protected function markSlotRequeue(LeadDocument $document, string $provider): array
    {
        $metadata = $document->metadata ?? [];
        $queuedAt = data_get($metadata, 'queued_at') ?? $document->uploaded_at?->toIso8601String() ?? $document->created_at?->toIso8601String();

        data_set($metadata, 'extraction_pipeline.queue_wait_started_at', data_get($metadata, 'extraction_pipeline.queue_wait_started_at', $queuedAt));
        data_set($metadata, 'extraction_pipeline.gemini_slot_requeues', (int) data_get($metadata, 'extraction_pipeline.gemini_slot_requeues', 0) + 1);
        data_set($metadata, 'extraction_pipeline.last_slot_requeue_at', now()->toIso8601String());
        data_set($metadata, 'extraction_pipeline.provider', $provider);

        $document->forceFill([
            'upload_status' => UploadStatus::QUEUED,
            'metadata' => $metadata,
        ])->save();

        return $metadata;
    }

    protected function markProcessingStarted(LeadDocument $document, string $provider, bool $geminiSlotAcquired): array
    {
        $metadata = $document->metadata ?? [];
        $processingStartedAt = now();
        $queuedAt = data_get($metadata, 'queued_at') ?? $document->uploaded_at?->toIso8601String() ?? $document->created_at?->toIso8601String();
        $queueStart = filled($queuedAt) ? Carbon::parse($queuedAt) : $document->created_at;

        $metadata['processing_started_at'] = $processingStartedAt->toIso8601String();
        data_set($metadata, 'extraction_pipeline.queue_wait_started_at', data_get($metadata, 'extraction_pipeline.queue_wait_started_at', $queuedAt));
        data_set($metadata, 'extraction_pipeline.processing_started_at', $processingStartedAt->toIso8601String());
        data_set($metadata, 'extraction_pipeline.queue_wait_seconds', $queueStart ? max(0, $queueStart->diffInSeconds($processingStartedAt)) : null);
        data_set($metadata, 'extraction_pipeline.provider', $provider);

        if ($geminiSlotAcquired) {
            data_set($metadata, 'extraction_pipeline.gemini_slot_acquired_at', $processingStartedAt->toIso8601String());
        }

        $document->forceFill([
            'upload_status' => UploadStatus::PROCESSING,
            'metadata' => $metadata,
        ])->save();

        return $metadata;
    }
}
