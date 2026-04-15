<?php

namespace App\Http\Requests;

use App\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadLeadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(array_column(DocumentType::cases(), 'value'))],
            'document_slot' => ['nullable', Rule::in(DocumentType::allowedUploadSlots())],
            'file' => ['required', 'file', 'max:10240'],
        ];
    }
}