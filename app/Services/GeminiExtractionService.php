<?php

namespace App\Services;

use App\Enums\DocumentType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiExtractionService
{
    public function extract(DocumentType $documentType, ?string $mimeType, string $base64Payload): array
    {
        $response = Http::timeout(60)
            ->acceptJson()
            ->post($this->endpoint(), [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $this->promptFor($documentType)],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType ?? 'application/octet-stream',
                                    'data' => $base64Payload,
                                ],
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        $response->throw();

        $text = collect(Arr::get($response->json(), 'candidates', []))
            ->flatMap(fn (array $candidate) => Arr::get($candidate, 'content.parts', []))
            ->pluck('text')
            ->filter()
            ->implode("\n");

        if ($text === '') {
            throw new RuntimeException('Gemini returned an empty extraction response.');
        }

        $decoded = $this->decodeJson($text);

        return [
            'summary' => $decoded['summary'] ?? 'Extraction completed.',
            'confidence' => $decoded['confidence'] ?? 'medium',
            'needs_review' => (bool) ($decoded['needs_review'] ?? false),
            'fields' => $decoded['fields'] ?? [],
            'raw_text' => $text,
        ];
    }

    protected function endpoint(): string
    {
        $baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        $model = config('services.gemini.model');
        $apiKey = config('services.gemini.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('Gemini API key is not configured.');
        }

        return "{$baseUrl}/models/{$model}:generateContent?key={$apiKey}";
    }

    protected function promptFor(DocumentType $documentType): string
    {
        return match ($documentType) {
            DocumentType::IC => <<<'PROMPT'
You are extracting structured data from a Malaysian identity card image.
Return valid JSON only with this shape:
{
  "summary": "short summary",
  "confidence": "high|medium|low",
  "needs_review": true,
  "fields": {
    "full_name": null,
    "ic_number": null,
    "date_of_birth": null,
    "address": null
  }
}
Rules:
- Use null when a value is missing or unclear.
- date_of_birth must be YYYY-MM-DD if confidently detected, otherwise null.
- needs_review must be true if any required identity field is unclear.
PROMPT,
            DocumentType::PAYSLIP => <<<'PROMPT'
You are extracting structured data from a payslip.
Return valid JSON only with this shape:
{
  "summary": "short summary",
  "confidence": "high|medium|low",
  "needs_review": true,
  "fields": {
    "employer": null,
    "employment_type": null,
    "basic_salary": null,
    "gross_income": null,
    "net_pay": null,
    "total_deductions": null
  }
}
Rules:
- Use null when a value is missing or unclear.
- Numeric fields must be numbers, not strings.
- needs_review must be true if employer or income fields are unclear.
PROMPT,
            default => throw new RuntimeException('Unsupported document type for Gemini extraction.'),
        };
    }

    protected function decodeJson(string $payload): array
    {
        $trimmed = trim($payload);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?|```$/m', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Gemini returned invalid JSON payload.');
        }

        return $decoded;
    }
}