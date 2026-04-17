<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentType;
use App\Enums\ExtractionStatus;
use App\Enums\UploadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateLeadDocumentAssignmentRequest;
use App\Http\Requests\UploadLeadDocumentRequest;
use App\Http\Requests\UploadLeadDocumentsBatchRequest;
use App\Jobs\DeleteLeadDocumentJob;
use App\Jobs\ProcessLeadDocumentJob;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Services\ActivityLogService;
use App\Services\DocumentService;
use App\Services\ExtractionService;
use App\Services\LeadCompletenessService;
use App\Services\LeadStageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

class LeadDocumentController extends Controller
{
    public function __construct(
        protected LeadCompletenessService $leadCompletenessService,
    ) {
    }

    public function storeBatch(
        UploadLeadDocumentsBatchRequest $request,
        Lead $lead,
        DocumentService $documentService,
        LeadCompletenessService $leadCompletenessService,
        ActivityLogService $activityLogService,
    ): JsonResponse {
        $documents = collect($request->file('files', []))
            ->map(function ($file) use ($lead, $documentService, $activityLogService) {
                $document = $documentService->registerUploadedFile($lead, $file);

                $activityLogService->log(
                    $lead,
                    'document.queued',
                    'Document queued for background processing.',
                    [
                        'document_id' => $document->id,
                        'document_type' => $document->document_type->value,
                        'file_name' => $document->original_filename,
                        'upload_status' => $document->upload_status->value,
                    ]
                );

                return $document;
            })
            ->values();

        $batch = $documents->isNotEmpty()
            ? Bus::batch($documents->map(fn (LeadDocument $document) => new ProcessLeadDocumentJob($document->id))->all())
                ->onQueue(config('queue.workloads.documents', 'documents'))
                ->name("lead-document-processing:{$lead->id}")
                ->dispatch()
            : null;

        if ($batch) {
            foreach ($documents as $document) {
                $metadata = $document->metadata ?? [];
                $metadata['job_batch_id'] = $batch->id;
                $document->forceFill(['metadata' => $metadata])->save();
            }
        }

        $lead->load('documents');
        $completeness = $leadCompletenessService->summarize($lead);

        return response()->json([
            'data' => [
                'batch_id' => $batch?->id,
                'uploaded_count' => $documents->count(),
                ...$this->statusPayload($lead, $completeness),
            ],
        ], 202);
    }

    public function store(
        UploadLeadDocumentRequest $request,
        Lead $lead,
        DocumentService $documentService,
        LeadCompletenessService $leadCompletenessService,
        ActivityLogService $activityLogService,
    ): JsonResponse {
        $document = $documentService->storeAndRegister(
            $lead,
            $request->file('file'),
            $request->validated('document_type'),
            $request->validated('document_slot')
        );

        $lead->load('documents');
        $completeness = $leadCompletenessService->summarize($lead);

        ProcessLeadDocumentJob::dispatch($document->id);

        $activityLogService->log(
            $lead,
            'document.queued',
            'Document queued for background processing.',
            [
                'document_id' => $document->id,
                'document_type' => $document->document_type->value,
            ]
        );

        return response()->json([
            'data' => [
                'document' => [
                    'id' => $document->id,
                    'document_type' => $document->document_type->value,
                    'original_filename' => $document->original_filename,
                    'storage_path' => $document->storage_path,
                    'upload_status' => $document->upload_status->value,
                    'uploaded_at' => $document->uploaded_at?->toIso8601String(),
                    'metadata' => $document->metadata,
                ],
                ...$this->statusPayload($lead, $completeness),
            ],
        ], 202);
    }

    public function status(
        Lead $lead,
        LeadCompletenessService $leadCompletenessService,
    ): JsonResponse {
        $lead->load(['documents', 'extractedData']);
        $completeness = $leadCompletenessService->summarize($lead);

        return response()->json([
            'data' => $this->statusPayload($lead, $completeness),
        ]);
    }

    public function preview(Lead $lead, LeadDocument $document): Response
    {
        abort_unless($document->lead_id === $lead->id, 404);
        abort_if(blank($document->storage_disk) || blank($document->storage_path), 404);
        abort_unless(Storage::disk($document->storage_disk)->exists($document->storage_path), 404);

        $mimeType = data_get($document->metadata, 'mime_type')
            ?? Storage::disk($document->storage_disk)->mimeType($document->storage_path)
            ?? 'application/octet-stream';

        return response(
            Storage::disk($document->storage_disk)->get($document->storage_path),
            200,
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="' . addslashes($document->original_filename ?: 'document') . '"',
            ]
        );
    }

    public function updateAssignment(
        UpdateLeadDocumentAssignmentRequest $request,
        Lead $lead,
        LeadDocument $document,
        DocumentService $documentService,
        LeadCompletenessService $leadCompletenessService,
        LeadStageService $leadStageService,
        ActivityLogService $activityLogService,
    ): JsonResponse {
        abort_unless($document->lead_id === $lead->id, 404);

        $assignmentKey = $request->validated('assignment_key');
        $metadata = $document->metadata ?? [];
        $metadata['manual_assignment_key'] = $assignmentKey;
        $metadata['manual_review_resolved'] = filled($assignmentKey);
        $metadata['effective_document_type'] = $this->documentTypeFromAssignmentKey($assignmentKey) ?? ($metadata['classification']['document_type'] ?? $document->document_type->value);

        $document->forceFill([
            'document_type' => $metadata['effective_document_type'],
            'metadata' => $metadata,
        ])->save();

        $lead->load(['documents', 'extractedData']);
        $completeness = $leadCompletenessService->summarize($lead);
        $lead = $leadStageService->syncFromDocumentCompleteness($lead, $completeness);

        $activityLogService->log(
            $lead,
            'document.assignment_updated',
            'Document assignment updated.',
            [
                'document_id' => $document->id,
                'assignment_key' => $assignmentKey,
            ]
        );

        return response()->json([
            'data' => [
                'lead_stage' => $lead->stage->value,
                'document_completeness' => $completeness,
            ],
        ]);
    }

    public function destroy(
        Lead $lead,
        LeadDocument $document,
        LeadCompletenessService $leadCompletenessService,
    ): JsonResponse {
        abort_unless($document->lead_id === $lead->id, 404);

        $document->forceFill(['upload_status' => UploadStatus::DELETING])->save();

        DeleteLeadDocumentJob::dispatch(
            $document->id,
            $lead->id,
            $document->original_filename,
            data_get($document->metadata, 'effective_document_type') ?? $document->document_type->value,
        );

        $lead->load('documents');
        $completeness = $leadCompletenessService->summarize($lead);

        return response()->json([
            'data' => [
                'deleted_document_id' => $document->id,
                ...$this->statusPayload($lead, $completeness),
            ],
        ], 202);
    }

    protected function statusPayload(Lead $lead, array $completeness): array
    {
        $lead->loadMissing(['documents', 'extractedData']);
        $assignmentKeys = $this->leadCompletenessService->documentAssignmentKeys($lead);
        $activeJobCount = LeadDocument::query()
            ->where('lead_id', $lead->id)
            ->whereIn('upload_status', [UploadStatus::QUEUED->value, UploadStatus::PROCESSING->value, UploadStatus::DELETING->value])
            ->count();

        return [
            'lead_stage' => $lead->stage->value,
            'document_completeness' => $completeness,
            'has_processing_documents' => $activeJobCount > 0,
            'active_job_count' => $activeJobCount,
            'documents' => $lead->documents->map(fn (LeadDocument $document) => [
                'id' => $document->id,
                'document_type' => $document->document_type->value,
                'original_filename' => $document->original_filename,
                'upload_status' => $document->upload_status->value,
                'uploaded_at' => $document->uploaded_at?->toIso8601String(),
                'classification' => data_get($document->metadata, 'classification'),
                'manual_assignment_key' => data_get($document->metadata, 'manual_assignment_key'),
                'assigned_checklist_key' => $assignmentKeys[$document->id] ?? null,
                'manual_review_resolved' => (bool) data_get($document->metadata, 'manual_review_resolved', false),
                'effective_document_type' => data_get($document->metadata, 'effective_document_type'),
                'metadata' => $document->metadata,
            ])->values(),
            'extracted_data' => $lead->extractedData->map(fn ($item) => [
                'id' => $item->id,
                'document_id' => $item->lead_document_id,
                'document_type' => $item->document_type->value,
                'summary' => $item->extracted_summary,
                'structured_fields' => $item->structured_fields,
                'status' => $item->extraction_status->value,
                'extracted_at' => $item->extracted_at?->toIso8601String(),
            ])->values(),
        ];
    }

    protected function documentTypeFromAssignmentKey(?string $assignmentKey): ?string
    {
        return match (true) {
            blank($assignmentKey) => null,
            str_starts_with($assignmentKey, 'ic_') => 'ic',
            str_starts_with($assignmentKey, 'payslip_') => 'payslip',
            str_starts_with($assignmentKey, 'epf_') => 'epf',
            $assignmentKey === 'ramci' => 'ramci',
            $assignmentKey === 'ctos' => 'ctos',
            default => null,
        };
    }
}