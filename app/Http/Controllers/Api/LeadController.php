<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportLeadsRequest;
use App\Http\Requests\StoreLeadRequest;
use App\Enums\CalculationStatus;
use App\Enums\DocumentType;
use App\Enums\EligibilityStatus;
use App\Enums\ExtractionStatus;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Enums\LeadStage;
use App\Enums\MatchStatus;
use App\Enums\UploadStatus;
use App\Services\LeadCompletenessService;
use App\Services\LeadService;
use BackedEnum;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

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
        $date = $request->string('date')->toString();

        $leads = Lead::query()
            ->with(['profile'])
            ->withCount('documents')
            ->when(
                $request->filled('stage'),
                fn ($query) => $query->where('stage', $request->string('stage')->toString())
            )
            ->when(
                $request->boolean('recent'),
                fn ($query) => $query->where('created_at', '>=', Carbon::now()->subMinutes(15))
            )
            ->when(
                $date !== '',
                fn ($query) => $query->whereDate('created_at', $date)
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
        try {
            $result = $leadService->import($request->validated('rows'));

            return response()->json([
                'data' => [
                    'created_count' => $result['created']->count(),
                    'duplicate_count' => $result['duplicates']->count(),
                    'created' => $result['created']->map(fn (Lead $lead) => $this->transformSummary($lead))->values(),
                    'duplicates' => $result['duplicates']->values(),
                ],
            ], 201);
        } catch (QueryException $exception) {
            report($exception);

            return response()->json([
                'message' => $this->databaseImportErrorMessage($exception),
            ], 503);
        } catch (Throwable $exception) {
            report($exception);

            if (str_contains($exception->getMessage(), 'Maximum execution time')) {
                return response()->json([
                    'message' => 'Lead import failed because the database did not respond in time. Check the MySQL connection and try again.',
                ], 503);
            }

            throw $exception;
        }
    }

    protected function databaseImportErrorMessage(QueryException $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'SQLSTATE[HY000] [2002]')) {
            return 'Lead import failed because the MySQL server could not be reached. Check the database connection and try again.';
        }

        return 'Lead import failed because the database is unavailable right now. Try again in a moment.';
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

    public function destroy(Lead $lead): JsonResponse
    {
        $lead->loadMissing('documents');

        foreach ($lead->documents as $document) {
            if (filled($document->storage_disk) && filled($document->storage_path)) {
                Storage::disk($document->storage_disk)->delete($document->storage_path);
            }
        }

        $lead->delete();

        return response()->json([
            'message' => 'Lead deleted successfully.',
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
            'stage' => $this->enumValue($lead->stage, LeadStage::class),
            'documents_count' => $lead->documents_count ?? $lead->documents()->count(),
            'created_at' => $lead->created_at?->toIso8601String(),
            'updated_at' => $lead->updated_at?->toIso8601String(),
        ];
    }

    protected function transformDetail(Lead $lead): array
    {
        $completeness = $this->leadCompletenessService->summarize($lead);
        $assignmentKeys = $this->leadCompletenessService->documentAssignmentKeys($lead);
        $activeJobCount = LeadDocument::query()
            ->where('lead_id', $lead->id)
            ->whereIn('upload_status', [UploadStatus::QUEUED->value, UploadStatus::PROCESSING->value, UploadStatus::DELETING->value])
            ->count();

        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'phone_number' => $lead->phone_number,
            'ic_number' => $lead->ic_number,
            'source' => $lead->source,
            'stage' => $this->enumValue($lead->stage, LeadStage::class),
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
            'has_processing_documents' => $activeJobCount > 0,
            'active_job_count' => $activeJobCount,
            'documents' => $lead->documents->map(fn ($document) => [
                'id' => $document->id,
                'document_type' => $this->enumValue($document->document_type, DocumentType::class),
                'original_filename' => $document->original_filename,
                'storage_disk' => $document->storage_disk,
                'storage_path' => $document->storage_path,
                'upload_status' => $this->enumValue($document->upload_status, UploadStatus::class),
                'uploaded_at' => $document->uploaded_at?->toIso8601String(),
                'metadata' => $document->metadata,
                'classification' => data_get($document->metadata, 'classification'),
                'manual_assignment_key' => data_get($document->metadata, 'manual_assignment_key'),
                'assigned_checklist_key' => $assignmentKeys[$document->id] ?? null,
                'manual_review_resolved' => (bool) data_get($document->metadata, 'manual_review_resolved', false),
                'effective_document_type' => data_get($document->metadata, 'effective_document_type'),
            ])->values(),
            'extracted_data' => $lead->extractedData->map(fn ($item) => [
                'id' => $item->id,
                'document_id' => $item->lead_document_id,
                'document_type' => $this->enumValue($item->document_type, DocumentType::class),
                'summary' => $item->extracted_summary,
                'structured_fields' => $item->structured_fields,
                'status' => $this->enumValue($item->extraction_status, ExtractionStatus::class),
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
                'eligibility_status' => $this->enumValue($result->eligibility_status, EligibilityStatus::class),
                'calculation_status' => $this->enumValue($result->calculation_status, CalculationStatus::class),
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
                'match_status' => $this->enumValue($match->match_status, MatchStatus::class),
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
                'old_stage' => $this->enumValue($history->old_stage, LeadStage::class),
                'new_stage' => $this->enumValue($history->new_stage, LeadStage::class),
                'note' => $history->note,
                'changed_at' => $history->changed_at?->toIso8601String(),
            ])->values(),
            'created_at' => $lead->created_at?->toIso8601String(),
            'updated_at' => $lead->updated_at?->toIso8601String(),
        ];
    }

    protected function enumValue(mixed $value, string $enumClass): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value === null) {
            return null;
        }

        if (is_string($value) || is_int($value)) {
            return $enumClass::tryFrom($value)?->value ?? (string) $value;
        }

        return (string) $value;
    }
}