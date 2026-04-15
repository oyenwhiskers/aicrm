<?php

namespace App\Http\Requests;

use App\Enums\LeadStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateLeadStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stage' => ['required', new Enum(LeadStage::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}