<?php

namespace App\Services;

use App\Enums\CalculationStatus;
use App\Enums\DocumentType;
use App\Enums\EligibilityStatus;
use App\Models\Lead;
use App\Models\LeadCalculationResult;

class CalculationService
{
    public function __construct(
        protected ActivityLogService $activityLogService,
    ) {
    }

    public function calculate(Lead $lead, array $overrides = []): LeadCalculationResult
    {
        $lead->loadMissing('profile', 'extractedData');

        $profile = $lead->profile;
        $latestPayslipExtraction = $lead->extractedData()
            ->where('document_type', DocumentType::PAYSLIP->value)
            ->latest('extracted_at')
            ->latest('id')
            ->first();

        $payslipFields = $latestPayslipExtraction?->structured_fields['fields'] ?? [];
        $salary = $this->toFloat($profile?->salary) ?? $this->toFloat($payslipFields['gross_income'] ?? null) ?? $this->toFloat($payslipFields['basic_salary'] ?? null) ?? 0.0;
        $otherIncome = $this->toFloat($profile?->other_income) ?? 0.0;
        $recognizedIncome = $this->toFloat($overrides['recognized_income'] ?? null) ?? ($salary + $otherIncome);
        $currentCommitments = $this->toFloat($overrides['current_commitments'] ?? null) ?? $this->toFloat($payslipFields['total_deductions'] ?? null) ?? 0.0;

        $maxDsrPercentage = $this->toFloat($overrides['max_dsr_percentage'] ?? null) ?? 60.0;
        $annualInterestRate = $this->toFloat($overrides['annual_interest_rate'] ?? null) ?? 8.0;
        $tenureMonths = max(12, min((int) ($overrides['tenure_months'] ?? 60), 120));
        $requestedAmount = $this->toFloat($overrides['requested_amount'] ?? null);

        $maxMonthlyCommitment = round($recognizedIncome * ($maxDsrPercentage / 100), 2);
        $availableInstallmentCapacity = max(round($maxMonthlyCommitment - $currentCommitments, 2), 0.0);
        $allowedFinancingAmount = $this->roundDownToHundred(
            $this->principalFromInstallment($availableInstallmentCapacity, $annualInterestRate, $tenureMonths)
        );

        $selectedFinancingAmount = $requestedAmount !== null && $requestedAmount > 0
            ? min($requestedAmount, $allowedFinancingAmount)
            : $allowedFinancingAmount;

        $installment = round(
            $this->installmentForPrincipal($selectedFinancingAmount, $annualInterestRate, $tenureMonths),
            2
        );

        $stampDuty = round($selectedFinancingAmount * 0.005, 2);
        $processingFee = round($selectedFinancingAmount * 0.01, 2);
        $payoutEstimate = max(round($selectedFinancingAmount - $stampDuty - $processingFee, 2), 0.0);
        $dsrResult = $recognizedIncome > 0
            ? round(($currentCommitments / $recognizedIncome) * 100, 2)
            : null;

        $eligibilityStatus = $this->determineEligibility(
            $recognizedIncome,
            $selectedFinancingAmount,
            $latestPayslipExtraction !== null,
            $latestPayslipExtraction?->extraction_status->value
        );

        $result = $lead->calculationResults()->create([
            'total_recognized_income' => $recognizedIncome,
            'total_commitments' => $currentCommitments,
            'dsr_result' => $dsrResult,
            'allowed_financing_amount' => $selectedFinancingAmount,
            'installment' => $installment,
            'payout_result' => $payoutEstimate,
            'eligibility_status' => $eligibilityStatus,
            'calculation_status' => CalculationStatus::COMPLETED,
            'input_snapshot' => [
                'salary' => $salary,
                'other_income' => $otherIncome,
                'requested_amount' => $requestedAmount,
                'tenure_months' => $tenureMonths,
                'annual_interest_rate' => $annualInterestRate,
                'max_dsr_percentage' => $maxDsrPercentage,
                'current_commitments' => $currentCommitments,
            ],
            'result_breakdown' => [
                'max_monthly_commitment' => $maxMonthlyCommitment,
                'available_installment_capacity' => $availableInstallmentCapacity,
                'estimated_stamp_duty' => $stampDuty,
                'estimated_processing_fee' => $processingFee,
                'used_latest_payslip_extraction' => $latestPayslipExtraction?->id,
                'assumptions' => [
                    'prototype_mode' => true,
                    'rate_type' => 'amortized_monthly_estimate',
                    'payout_model' => 'financing - stamp duty - processing fee',
                ],
            ],
            'processed_at' => now(),
        ]);

        $this->activityLogService->log(
            $lead,
            'calculation.completed',
            'Simplified financing calculation completed.',
            [
                'calculation_result_id' => $result->id,
                'eligibility_status' => $result->eligibility_status->value,
                'allowed_financing_amount' => $result->allowed_financing_amount,
            ]
        );

        return $result;
    }

    protected function installmentForPrincipal(float $principal, float $annualInterestRate, int $tenureMonths): float
    {
        if ($principal <= 0) {
            return 0.0;
        }

        $monthlyRate = ($annualInterestRate / 100) / 12;

        if ($monthlyRate == 0.0) {
            return $principal / $tenureMonths;
        }

        return $principal * $monthlyRate / (1 - (1 + $monthlyRate) ** (-$tenureMonths));
    }

    protected function principalFromInstallment(float $installment, float $annualInterestRate, int $tenureMonths): float
    {
        if ($installment <= 0) {
            return 0.0;
        }

        $monthlyRate = ($annualInterestRate / 100) / 12;

        if ($monthlyRate == 0.0) {
            return $installment * $tenureMonths;
        }

        return $installment * ((1 - (1 + $monthlyRate) ** (-$tenureMonths)) / $monthlyRate);
    }

    protected function roundDownToHundred(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        return floor($amount / 100) * 100;
    }

    protected function determineEligibility(float $recognizedIncome, float $selectedFinancingAmount, bool $hasPayslipExtraction, ?string $extractionStatus): EligibilityStatus
    {
        if ($recognizedIncome <= 0) {
            return EligibilityStatus::INCOMPLETE;
        }

        if (! $hasPayslipExtraction) {
            return EligibilityStatus::MANUAL_REVIEW;
        }

        if ($extractionStatus === 'review_required') {
            return EligibilityStatus::MANUAL_REVIEW;
        }

        if ($selectedFinancingAmount < 1000) {
            return EligibilityStatus::NOT_ELIGIBLE;
        }

        return EligibilityStatus::ELIGIBLE;
    }

    protected function toFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}