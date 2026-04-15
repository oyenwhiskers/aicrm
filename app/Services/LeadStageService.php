<?php

namespace App\Services;

use App\Enums\LeadStage;
use App\Models\Lead;

class LeadStageService
{
    public function transition(Lead $lead, LeadStage $newStage, ?string $note = null): Lead
    {
        $oldStage = $lead->stage;

        if ($oldStage === $newStage) {
            return $lead;
        }

        $lead->forceFill(['stage' => $newStage])->save();

        $lead->stageHistories()->create([
            'old_stage' => $oldStage,
            'new_stage' => $newStage,
            'changed_at' => now(),
            'note' => $note,
        ]);

        return $lead->refresh();
    }

    public function syncFromDocumentCompleteness(Lead $lead, array $completeness): Lead
    {
        if ($completeness['is_complete']) {
            return $this->transition($lead, LeadStage::DOC_COMPLETE, 'All required prototype documents received.');
        }

        if ($completeness['received_required_document_count'] > 0) {
            return $this->transition($lead, LeadStage::DOC_PARTIAL, 'Some required prototype documents received.');
        }

        if (in_array($lead->stage, [LeadStage::DOC_REQUESTED, LeadStage::DOC_PARTIAL, LeadStage::DOC_COMPLETE], true)) {
            return $this->transition($lead, LeadStage::DOC_REQUESTED, 'No required prototype documents currently uploaded.');
        }

        return $lead;
    }
}