<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RunLeadCalculationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recognized_income' => ['nullable', 'numeric', 'min:0'],
            'current_commitments' => ['nullable', 'numeric', 'min:0'],
            'requested_amount' => ['nullable', 'numeric', 'min:0'],
            'tenure_months' => ['nullable', 'integer', 'min:12', 'max:120'],
            'annual_interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_dsr_percentage' => ['nullable', 'numeric', 'min:1', 'max:100'],
        ];
    }
}