<?php

namespace App\Http\Controllers\Api;

use App\Enums\EligibilityStatus;
use App\Enums\LeadStage;
use App\Http\Controllers\Controller;
use App\Http\Requests\RunLeadCalculationRequest;
use App\Models\Lead;
use App\Services\CalculationService;
use App\Services\LeadCompletenessService;
use App\Services\LeadStageService;
use Illuminate\Http\JsonResponse;

class LeadProcessingController extends Controller
{
    public function calculate(
        RunLeadCalculationRequest $request,
        Lead $lead,
        CalculationService $calculationService,
        LeadCompletenessService $leadCompletenessService,
        LeadStageService $leadStageService,
    ): JsonResponse {
        $lead->loadMissing('documents');
        $completeness = $leadCompletenessService->summarize($lead);

        if (! $completeness['is_complete']) {
            return response()->json([
                'message' => 'Complete all required documents before running calculation.',
            ], 422);
        }

        $leadStageService->transition($lead, LeadStage::PROCESSING, 'Calculation started.');

        $result = $calculationService->calculate($lead, $request->validated());

        $nextStage = match ($result->eligibility_status) {
            EligibilityStatus::ELIGIBLE => LeadStage::PROCESSED,
            EligibilityStatus::NOT_ELIGIBLE => LeadStage::NOT_ELIGIBLE,
            default => LeadStage::MANUAL_REVIEW,
        };

        $lead = $leadStageService->transition($lead, $nextStage, 'Calculation completed.');

        return response()->json([
            'data' => [
                'id' => $result->id,
                'lead_id' => $lead->id,
                'stage' => $lead->stage->value,
                'eligibility_status' => $result->eligibility_status->value,
                'calculation_status' => $result->calculation_status->value,
                'total_recognized_income' => $result->total_recognized_income,
                'total_commitments' => $result->total_commitments,
                'dsr_result' => $result->dsr_result,
                'allowed_financing_amount' => $result->allowed_financing_amount,
                'installment' => $result->installment,
                'payout_result' => $result->payout_result,
                'breakdown' => $result->result_breakdown,
                'processed_at' => $result->processed_at?->toIso8601String(),
            ],
        ]);
    }
}