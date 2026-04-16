<?php

namespace App\Services;

use App\Enums\DocumentType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiExtractionService
{
    public function extract(?string $mimeType, string $base64Payload): array
    {
        $decoded = $this->requestJson($this->documentWorkflowPrompt(), $mimeType, $base64Payload);

        return [
            'summary' => $decoded['summary'] ?? 'Extraction completed.',
            'confidence' => $decoded['confidence'] ?? 'medium',
            'needs_review' => (bool) ($decoded['needs_review'] ?? false),
            'classification' => [
                'document_type' => $decoded['classification']['document_type'] ?? DocumentType::OTHER->value,
                'ic_side' => $decoded['classification']['ic_side'] ?? null,
                'statement_year' => $decoded['classification']['statement_year'] ?? null,
                'statement_month' => $decoded['classification']['statement_month'] ?? null,
                'statement_period' => $decoded['classification']['statement_period'] ?? null,
            ],
            'fields' => $decoded['fields'] ?? [],
            'raw_text' => $decoded['_raw_text'] ?? null,
        ];
    }

    public function extractLeadCaptureImage(?string $mimeType, string $base64Payload): array
    {
        $decoded = $this->requestJson($this->leadCapturePrompt(), $mimeType, $base64Payload);

        return [
            'summary' => $decoded['summary'] ?? 'Lead image extraction completed.',
            'needs_review' => (bool) ($decoded['needs_review'] ?? false),
            'rows' => $decoded['rows'] ?? [],
            'raw_text' => $decoded['_raw_text'] ?? null,
        ];
    }

    protected function requestJson(string $prompt, ?string $mimeType, string $base64Payload): array
    {
        try {
            $response = Http::timeout(60)
                ->withOptions([
                    'verify' => (bool) config('services.gemini.verify_ssl', true),
                ])
                ->retry(3, 2000, function (\Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException) {
                        $status = $exception->response?->status();

                        return in_array($status, [408, 429, 500, 502, 503, 504], true);
                    }

                    return false;
                })
                ->acceptJson()
                ->post($this->endpoint(), [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
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
        } catch (ConnectionException $exception) {
            if (str_contains($exception->getMessage(), 'cURL error 60')) {
                throw new RuntimeException(
                    'Gemini SSL verification failed on this machine. Set GEMINI_VERIFY_SSL=false for local development or install a valid CA bundle for PHP.',
                    previous: $exception,
                );
            }

            throw $exception;
        }

        if ($response->failed()) {
            $body = (string) $response->body();

            if (
                $response->status() === 400
                && str_contains($body, 'Only image types are supported')
                && str_contains((string) $mimeType, 'pdf')
            ) {
                throw new RuntimeException(
                    'The upstream AI endpoint rejected PDF input as image-only. Ensure GEMINI_BASE_URL points to Google Generative Language API and restart queue workers after env changes.'
                );
            }
        }

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
        $decoded['_raw_text'] = $text;

        return $decoded;
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

        protected function documentWorkflowPrompt(): string
    {
                return <<<'PROMPT'
You are classifying and extracting data from a Malaysian loan document.
Return valid JSON only with this exact shape:
{
    "summary": "short summary",
    "confidence": "high|medium|low",
    "needs_review": true,
    "classification": {
        "document_type": "ic|payslip|epf|ramci|ctos|other",
        "ic_side": "front|back|null",
        "statement_year": null,
        "statement_month": null,
        "statement_period": null
    },
    "fields": {
        "full_name": null,
        "ic_number": null,
        "date_of_birth": null,
        "address": null,
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
- `document_type` must be one of: ic, payslip, epf, ramci, ctos, other.
- For IC, set `ic_side` to front or back when confident.
- IC front usually shows the person's name, IC number, and identity details such as date of birth.
- IC back should be recognized from reverse-side markers such as Touch 'n Go, chip text, "Ketua Pengarah Pendaftaran Negara", "Pendaftaran Negara", or other back-side printing. Do not require an address to classify it as back.
- If the image looks like the blue patterned reverse side and the person's full name is not visible, prefer `ic_side = back` even when an address is absent.
- For payslip, set `statement_period` to YYYY-MM when confident. Also set `statement_year` and `statement_month`.
- For EPF, set `statement_year` when confident.
- Numeric fields must be numbers, not strings.
- `needs_review` must be true if document type is unclear or any required classification detail is unclear.
- Return JSON only.
PROMPT;
    }

        protected function leadCapturePrompt(): string
        {
                return <<<'PROMPT'
You are reading a screenshot or image that contains a list of loan leads.
Extract each visible lead entry into structured JSON.
Return valid JSON only with this exact shape:
{
    "summary": "short summary",
    "needs_review": true,
    "rows": [
        {
            "name": null,
            "phone_number": null,
            "raw_name": null,
            "raw_phone_number": null,
            "confidence": "high|medium|low",
            "notes": null
        }
    ]
}
Rules:
- Only include rows where both a name and phone number are visible.
- Prefer the human full name when visible. If only username is visible, use that as name.
- Keep phone_number in the closest readable form from the image.
- Use null when uncertain.
- needs_review must be true if any row is partially ambiguous.
- Return JSON only, no markdown.
PROMPT;
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