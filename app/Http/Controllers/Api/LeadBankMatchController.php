<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadStage;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\BankMatchingService;
use App\Services\LeadStageService;
use Illuminate\Http\JsonResponse;

class LeadBankMatchController extends Controller
{
    public function store(
        Lead $lead,
        BankMatchingService $bankMatchingService,
        LeadStageService $leadStageService,
    ): JsonResponse {
        $result = $bankMatchingService->match($lead);

        $nextStage = LeadStage::NOT_ELIGIBLE;

        if ($result['matched_count'] > 0) {
            $nextStage = LeadStage::MATCHED;
        } elseif ($result['manual_review_count'] > 0) {
            $nextStage = LeadStage::MANUAL_REVIEW;
        }

        $lead = $leadStageService->transition($lead, $nextStage, 'Bank matching completed.');

        return response()->json([
            'data' => [
                'lead_id' => $lead->id,
                'stage' => $lead->stage->value,
                'matched_count' => $result['matched_count'],
                'manual_review_count' => $result['manual_review_count'],
                'matches' => collect($result['matches'])->map(fn ($match) => [
                    'id' => $match->id,
                    'bank' => [
                        'id' => $match->bank?->id,
                        'name' => $match->bank?->name,
                        'code' => $match->bank?->code,
                    ],
                    'match_status' => $match->match_status->value,
                    'match_reason' => $match->match_reason,
                    'matched_at' => $match->matched_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }
}