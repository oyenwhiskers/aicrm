<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadStage;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateLeadStageRequest;
use App\Models\Lead;
use App\Services\ActivityLogService;
use App\Services\LeadStageService;
use Illuminate\Http\JsonResponse;

class LeadStageController extends Controller
{
    public function update(
        UpdateLeadStageRequest $request,
        Lead $lead,
        LeadStageService $leadStageService,
        ActivityLogService $activityLogService,
    ): JsonResponse {
        $newStage = LeadStage::from($request->validated('stage'));
        $lead = $leadStageService->transition($lead, $newStage, $request->validated('note'));

        $activityLogService->log(
            $lead,
            'stage.updated',
            'Lead stage updated.',
            [
                'new_stage' => $lead->stage->value,
                'note' => $request->validated('note'),
            ]
        );

        return response()->json([
            'data' => [
                'id' => $lead->id,
                'stage' => $lead->stage->value,
                'updated_at' => $lead->updated_at?->toIso8601String(),
            ],
        ]);
    }
}