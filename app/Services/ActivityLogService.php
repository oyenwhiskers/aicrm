<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivityLog;

class ActivityLogService
{
    public function log(Lead $lead, string $actionType, string $actionDetail, array $context = []): LeadActivityLog
    {
        return $lead->activityLogs()->create([
            'action_type' => $actionType,
            'action_detail' => $actionDetail,
            'context' => $context,
        ]);
    }
}