<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadLeadDocumentRequest;
use App\Models\Lead;
use App\Services\ActivityLogService;
use App\Services\DocumentService;
use App\Services\ExtractionService;
use App\Services\LeadCompletenessService;
use App\Services\LeadStageService;
use Illuminate\Http\JsonResponse;

class LeadDocumentController extends Controller
{
    public function store(
        UploadLeadDocumentRequest $request,
        Lead $lead,
        DocumentService $documentService,
        ExtractionService $extractionService,
        LeadCompletenessService $leadCompletenessService,
        LeadStageService $leadStageService,
        ActivityLogService $activityLogService,
    ): JsonResponse {
        $document = $documentService->storeAndRegister(
            $lead,
            $request->file('file'),
            $request->validated('document_type')
        );

        $extraction = $extractionService->extract($document);

        $lead->load('documents');
        $completeness = $leadCompletenessService->summarize($lead);
        $lead = $leadStageService->syncFromDocumentCompleteness($lead, $completeness);

        $activityLogService->log(
            $lead,
            'document.uploaded',
            'Document uploaded.',
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
                'extraction' => [
                    'id' => $extraction->id,
                    'status' => $extraction->extraction_status->value,
                    'summary' => $extraction->extracted_summary,
                    'structured_fields' => $extraction->structured_fields,
                    'extracted_at' => $extraction->extracted_at?->toIso8601String(),
                ],
                'lead_stage' => $lead->stage->value,
                'document_completeness' => $completeness,
            ],
        ], 201);
    }
}