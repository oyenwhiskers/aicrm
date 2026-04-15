<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.phone_number' => ['required', 'string', 'max:50'],
            'rows.*.ic_number' => ['nullable', 'string', 'max:50'],
            'rows.*.source' => ['nullable', 'string', 'max:255'],
        ];
    }
}