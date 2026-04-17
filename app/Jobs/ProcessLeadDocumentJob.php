<?php

namespace App\Jobs;

use App\Enums\ExtractionStatus;
use App\Enums\UploadStatus;
use App\Models\LeadDocument;
use App\Services\ActivityLogService;
use App\Services\ExtractionService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessLeadDocumentJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public function __construct(
        public int $documentId,
    ) {
        $this->onQueue(config('queue.workloads.documents', 'documents'));
    }

    public function handle(ExtractionService $extractionService, ActivityLogService $activityLogService): void
    {
        $document = LeadDocument::query()
            ->with('lead.profile')
            ->find($this->documentId);

        if (! $document || $document->upload_status === UploadStatus::DELETING) {
            return;
        }

        $metadata = $document->metadata ?? [];
        $metadata['processing_started_at'] = now()->toIso8601String();

        $document->forceFill([
            'upload_status' => UploadStatus::PROCESSING,
            'metadata' => $metadata,
        ])->save();

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
            RefreshLeadDocumentStateJob::dispatch($document->lead_id);
        }
    }
}