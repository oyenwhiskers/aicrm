<?php

namespace App\Services;

use App\Enums\LeadStage;
use App\Enums\MatchStatus;
use App\Models\Bank;
use App\Models\Lead;
use App\Models\LeadCalculationResult;

class BankMatchingService
{
    public function __construct(
        protected ActivityLogService $activityLogService,
    ) {
    }

    public function match(Lead $lead): array
    {
        $lead->loadMissing('profile');

        $calculationResult = $lead->calculationResults()->latest('processed_at')->latest('id')->first();

        if (! $calculationResult instanceof LeadCalculationResult) {
            throw new \RuntimeException('A calculation result is required before bank matching can run.');
        }

        $matches = [];
        $matchedCount = 0;
        $manualReviewCount = 0;

        foreach (Bank::query()->with('rule')->where('is_active', true)->orderBy('name')->get() as $bank) {
            [$status, $reasons] = $this->evaluateBank($lead, $calculationResult, $bank);

            if ($status === MatchStatus::MATCHED) {
                $matchedCount++;
            }

            if ($status === MatchStatus::MANUAL_REVIEW) {
                $manualReviewCount++;
            }

            $matches[] = $lead->bankMatches()->updateOrCreate(
                ['bank_id' => $bank->id],
                [
                    'match_status' => $status,
                    'match_reason' => implode('; ', $reasons),
                    'matched_at' => now(),
                ]
            )->load('bank');
        }

        $this->activityLogService->log(
            $lead,
            'bank_matching.completed',
            'Basic bank matching completed.',
            [
                'matched_count' => $matchedCount,
                'manual_review_count' => $manualReviewCount,
            ]
        );

        return [
            'matches' => $matches,
            'matched_count' => $matchedCount,
            'manual_review_count' => $manualReviewCount,
        ];
    }

    protected function evaluateBank(Lead $lead, LeadCalculationResult $calculationResult, Bank $bank): array
    {
        $rule = $bank->rule;

        if ($rule === null) {
            return [MatchStatus::MANUAL_REVIEW, ['No bank rule is configured for this bank.']];
        }

        $profile = $lead->profile;
        $reasons = [];
        $status = MatchStatus::MATCHED;

        $sector = strtolower((string) ($profile?->sector ?? ''));
        $acceptedSectors = collect($rule->accepted_sectors ?? [])->map(fn ($value) => strtolower((string) $value))->filter()->values();

        if ($acceptedSectors->isNotEmpty()) {
            if ($sector === '') {
                $status = MatchStatus::MANUAL_REVIEW;
                $reasons[] = 'Applicant sector is missing.';
            } elseif (! $acceptedSectors->contains($sector)) {
                return [MatchStatus::NOT_MATCHED, ['Sector is not accepted by this bank.']];
            }
        }

        $salary = is_numeric($profile?->salary) ? (float) $profile->salary : null;

        if ($rule->minimum_salary !== null) {
            if ($salary === null) {
                $status = MatchStatus::MANUAL_REVIEW;
                $reasons[] = 'Salary is missing for bank comparison.';
            } elseif ($salary < (float) $rule->minimum_salary) {
                return [MatchStatus::NOT_MATCHED, ['Salary is below the bank minimum.']];
            }
        }

        if ($rule->max_loan_amount !== null && $calculationResult->allowed_financing_amount !== null) {
            if ((float) $calculationResult->allowed_financing_amount > (float) $rule->max_loan_amount) {
                return [MatchStatus::NOT_MATCHED, ['Allowed financing amount exceeds the bank maximum.']];
            }
        }

        if ($rule->max_dsr !== null && $calculationResult->dsr_result !== null) {
            if ((float) $calculationResult->dsr_result > (float) $rule->max_dsr) {
                return [MatchStatus::NOT_MATCHED, ['Current DSR exceeds the bank threshold.']];
            }
        } else {
            $status = MatchStatus::MANUAL_REVIEW;
            $reasons[] = 'DSR data is incomplete for matching.';
        }

        if ($status === MatchStatus::MATCHED) {
            $reasons[] = 'Applicant satisfies the prototype bank rules.';
        }

        return [$status, $reasons];
    }
}