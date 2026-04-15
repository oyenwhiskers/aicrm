<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\LeadCompletenessService;
use App\Services\LeadStageService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshLeadDocumentStateJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 30;

    public function __construct(
        public int $leadId,
    ) {
    }

    public function uniqueId(): string
    {
        return 'lead-document-refresh:' . $this->leadId;
    }

    public function handle(LeadCompletenessService $leadCompletenessService, LeadStageService $leadStageService): void
    {
        $lead = Lead::query()->find($this->leadId);

        if (! $lead) {
            return;
        }

        $lead->load('documents');
        $completeness = $leadCompletenessService->summarize($lead);
        $leadStageService->syncFromDocumentCompleteness($lead, $completeness);
    }
}