<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportLeadsRequest;
use App\Http\Requests\StoreLeadRequest;
use App\Models\Lead;
use App\Services\LeadCompletenessService;
use App\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function __construct(
        protected LeadCompletenessService $leadCompletenessService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('per_page', 15), 100);
        $search = $request->string('search')->toString();

        $leads = Lead::query()
            ->with(['profile'])
            ->withCount('documents')
            ->when(
                $request->filled('stage'),
                fn ($query) => $query->where('stage', $request->string('stage')->toString())
            )
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%")
                        ->orWhere('ic_number', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($perPage)
            ->through(fn (Lead $lead) => $this->transformSummary($lead));

        return response()->json($leads);
    }

    public function store(StoreLeadRequest $request, LeadService $leadService): JsonResponse
    {
        $lead = $leadService->create($request->validated());

        return response()->json([
            'data' => $this->transformDetail($lead->load(['profile', 'documents', 'extractedData', 'calculationResults', 'bankMatches.bank', 'activityLogs', 'stageHistories'])),
        ], 201);
    }

    public function import(ImportLeadsRequest $request, LeadService $leadService): JsonResponse
    {
        $result = $leadService->import($request->validated('rows'));

        return response()->json([
            'data' => [
                'created_count' => $result['created']->count(),
                'duplicate_count' => $result['duplicates']->count(),
                'created' => $result['created']->map(fn (Lead $lead) => $this->transformSummary($lead))->values(),
                'duplicates' => $result['duplicates']->values(),
            ],
        ], 201);
    }

    public function show(Lead $lead): JsonResponse
    {
        $lead->load([
            'profile',
            'documents',
            'extractedData.document',
            'calculationResults',
            'bankMatches.bank',
            'activityLogs',
            'stageHistories',
        ]);

        return response()->json([
            'data' => $this->transformDetail($lead),
        ]);
    }

    protected function transformSummary(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'phone_number' => $lead->phone_number,
            'ic_number' => $lead->ic_number,
            'source' => $lead->source,
            'stage' => $lead->stage->value,
            'documents_count' => $lead->documents_count ?? $lead->documents()->count(),
            'created_at' => $lead->created_at?->toIso8601String(),
            'updated_at' => $lead->updated_at?->toIso8601String(),
        ];
    }

    protected function transformDetail(Lead $lead): array
    {
        $completeness = $this->leadCompletenessService->summarize($lead);

        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'phone_number' => $lead->phone_number,
            'ic_number' => $lead->ic_number,
            'source' => $lead->source,
            'stage' => $lead->stage->value,
            'profile' => [
                'employer' => $lead->profile?->employer,
                'sector' => $lead->profile?->sector,
                'employment_type' => $lead->profile?->employment_type,
                'salary' => $lead->profile?->salary,
                'other_income' => $lead->profile?->other_income,
                'age' => $lead->profile?->age,
                'years_of_service' => $lead->profile?->years_of_service,
                'is_pensioner' => $lead->profile?->is_pensioner,
                'has_akpk' => $lead->profile?->has_akpk,
                'is_blacklisted' => $lead->profile?->is_blacklisted,
                'has_bnpl' => $lead->profile?->has_bnpl,
                'has_legal_or_saa_issue' => $lead->profile?->has_legal_or_saa_issue,
            ],
            'document_completeness' => $completeness,
            'documents' => $lead->documents->map(fn ($document) => [
                'id' => $document->id,
                'document_type' => $document->document_type->value,
                'original_filename' => $document->original_filename,
                'storage_disk' => $document->storage_disk,
                'storage_path' => $document->storage_path,
                'upload_status' => $document->upload_status->value,
                'uploaded_at' => $document->uploaded_at?->toIso8601String(),
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
            'calculation_results' => $lead->calculationResults->map(fn ($result) => [
                'id' => $result->id,
                'total_recognized_income' => $result->total_recognized_income,
                'total_commitments' => $result->total_commitments,
                'dsr_result' => $result->dsr_result,
                'allowed_financing_amount' => $result->allowed_financing_amount,
                'installment' => $result->installment,
                'payout_result' => $result->payout_result,
                'eligibility_status' => $result->eligibility_status->value,
                'calculation_status' => $result->calculation_status->value,
                'processed_at' => $result->processed_at?->toIso8601String(),
                'result_breakdown' => $result->result_breakdown,
            ])->values(),
            'bank_matches' => $lead->bankMatches->map(fn ($match) => [
                'id' => $match->id,
                'bank' => [
                    'id' => $match->bank?->id,
                    'name' => $match->bank?->name,
                    'code' => $match->bank?->code,
                ],
                'match_status' => $match->match_status->value,
                'match_reason' => $match->match_reason,
                'priority' => $match->priority,
                'matched_at' => $match->matched_at?->toIso8601String(),
            ])->values(),
            'activity_logs' => $lead->activityLogs->map(fn ($log) => [
                'id' => $log->id,
                'action_type' => $log->action_type,
                'action_detail' => $log->action_detail,
                'context' => $log->context,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'stage_history' => $lead->stageHistories->map(fn ($history) => [
                'id' => $history->id,
                'old_stage' => $history->old_stage?->value,
                'new_stage' => $history->new_stage->value,
                'note' => $history->note,
                'changed_at' => $history->changed_at?->toIso8601String(),
            ])->values(),
            'created_at' => $lead->created_at?->toIso8601String(),
            'updated_at' => $lead->updated_at?->toIso8601String(),
        ];
    }
}