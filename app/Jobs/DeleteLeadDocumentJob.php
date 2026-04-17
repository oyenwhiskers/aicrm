<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Services\ActivityLogService;
use App\Services\DocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteLeadDocumentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $documentId,
        public int $leadId,
        public string $fileName,
        public string $documentType,
    ) {
        $this->onQueue(config('queue.workloads.documents', 'documents'));
    }

    public function handle(DocumentService $documentService, ActivityLogService $activityLogService): void
    {
        $document = LeadDocument::query()->find($this->documentId);

        if ($document) {
            $documentService->deleteRegisteredDocument($document);
        }

        $lead = Lead::query()->find($this->leadId);

        if ($lead) {
            $activityLogService->log(
                $lead,
                'document.deleted',
                'Document deleted.',
                [
                    'document_id' => $this->documentId,
                    'file_name' => $this->fileName,
                    'document_type' => $this->documentType,
                ],
            );

            RefreshLeadDocumentStateJob::dispatch($lead->id);
        }
    }
}