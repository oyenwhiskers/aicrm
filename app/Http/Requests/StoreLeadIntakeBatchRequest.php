<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeadIntakeBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:25'],
            'images.*' => ['required', 'file', 'image', 'max:10240'],
            'source' => ['nullable', 'string', 'max:255'],
            'client_keys' => ['nullable', 'array'],
            'client_keys.*' => ['nullable', 'string', 'max:255'],
            'image_metadata' => ['nullable', 'array'],
            'image_metadata.*' => ['nullable', 'string', 'max:5000'],
        ];
    }
}