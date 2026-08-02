<?php

namespace App\Services;

use App\Enums\DocumentType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use RuntimeException;

class GeminiExtractionService
{
    public function __construct(
        protected AiUsageLogService $aiUsageLogService,
    ) {
    }

    public function extract(?string $mimeType, string $base64Payload, array $context = []): array
    {
        $decoded = $this->requestJson(
            $this->documentWorkflowPrompt(),
            $mimeType,
            $base64Payload,
            [
                'model' => config('services.gemini.model'),
                'retry_delays' => [2000, 5000, 10000],
                'context' => $context,
            ],
        );

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

    public function extractLeadCaptureImage(?string $mimeType, string $base64Payload, array $context = []): array
    {
        $decoded = $this->requestJson(
            $this->leadCapturePrompt(),
            $mimeType,
            $base64Payload,
            [
                'model' => config('services.gemini.intake_model', config('services.gemini.model')),
                'fallback_model' => config('services.gemini.intake_fallback_model'),
                'retry_delays' => $this->intakeRetryDelays(),
                'context' => $context,
            ],
        );

        return [
            'summary' => $decoded['summary'] ?? 'Lead image extraction completed.',
            'needs_review' => (bool) ($decoded['needs_review'] ?? false),
            'rows' => $decoded['rows'] ?? [],
            'raw_text' => $decoded['_raw_text'] ?? null,
        ];
    }

    protected function requestJson(string $prompt, ?string $mimeType, string $base64Payload, array $options = []): array
    {
        $models = collect([
            $options['model'] ?? config('services.gemini.model'),
            $options['fallback_model'] ?? null,
        ])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->unique()
            ->values();

        $retryDelays = $options['retry_delays'] ?? [2000, 5000, 10000];
        $lastException = null;

        foreach ($models as $index => $model) {
            try {
                return $this->requestJsonForModel(
                    $prompt,
                    $mimeType,
                    $base64Payload,
                    $model,
                    $retryDelays,
                    $options['context'] ?? [],
                );
            } catch (
                ConnectionException |
                RequestException |
                RuntimeException $exception
            ) {
                $lastException = $exception;

                if ($index === $models->count() - 1 || ! $this->shouldFallbackToNextModel($exception)) {
                    throw $exception;
                }
            }
        }

        throw $lastException ?? new RuntimeException('Gemini request did not return a response.');
    }

    protected function requestJsonForModel(string $prompt, ?string $mimeType, string $base64Payload, string $model, array $retryDelays, array $context = []): array
    {
        $response = null;
        $lastConnectionException = null;

        try {
            $maxAttempts = count($retryDelays) + 1;

            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                $requestStartedAt = Carbon::now();
                $startedMicrotime = microtime(true);

                try {
                    $response = Http::timeout(60)
                        ->withOptions([
                            'verify' => (bool) config('services.gemini.verify_ssl', true),
                        ])
                        ->acceptJson()
                        ->post($this->endpoint($model), [
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
                    $requestFinishedAt = Carbon::now();
                    $this->logGeminiAttempt(
                        context: $context,
                        model: $model,
                        requestStartedAt: $requestStartedAt,
                        requestFinishedAt: $requestFinishedAt,
                        latencyMs: $this->latencyMs($startedMicrotime),
                        httpStatus: null,
                        requestStatus: 'failed',
                        needsReview: false,
                        reviewReasons: [],
                        usage: [],
                        errorCode: 'connection_exception',
                        errorMessage: $exception->getMessage(),
                    );

                    if (str_contains($exception->getMessage(), 'cURL error 60')) {
                        throw new RuntimeException(
                            'Gemini SSL verification failed on this machine. Set GEMINI_VERIFY_SSL=false for local development or install a valid CA bundle for PHP.',
                            previous: $exception,
                        );
                    }

                    $lastConnectionException = $exception;

                    if (! array_key_exists($attempt, $retryDelays)) {
                        throw $exception;
                    }

                    usleep($retryDelays[$attempt] * 1000);
                    continue;
                }

                $requestFinishedAt = Carbon::now();
                $latencyMs = $this->latencyMs($startedMicrotime);

                if (! $this->shouldRetryResponse($response) || ! array_key_exists($attempt, $retryDelays)) {
                    break;
                }

                $this->logGeminiAttempt(
                    context: $context,
                    model: $model,
                    requestStartedAt: $requestStartedAt,
                    requestFinishedAt: $requestFinishedAt,
                    latencyMs: $latencyMs,
                    httpStatus: $response->status(),
                    requestStatus: 'failed',
                    needsReview: false,
                    reviewReasons: [],
                    usage: $this->extractUsageMetrics($response->json()),
                    errorCode: $this->responseErrorCode($response),
                    errorMessage: $this->responseErrorMessage($response),
                );

                usleep($retryDelays[$attempt] * 1000);
            }
        } catch (ConnectionException $exception) {
            if (str_contains($exception->getMessage(), 'cURL error 60')) {
                throw new RuntimeException(
                    'Gemini SSL verification failed on this machine. Set GEMINI_VERIFY_SSL=false for local development or install a valid CA bundle for PHP.',
                    previous: $exception,
                );
            }

            throw $exception;
        }

        if ($response === null && $lastConnectionException) {
            throw $lastConnectionException;
        }

        if ($response === null) {
            throw new RuntimeException('Gemini request did not return a response.');
        }

        if ($response->failed()) {
            $body = (string) $response->body();

            $this->logGeminiAttempt(
                context: $context,
                model: $model,
                requestStartedAt: $requestStartedAt ?? Carbon::now(),
                requestFinishedAt: $requestFinishedAt ?? Carbon::now(),
                latencyMs: $latencyMs ?? 0,
                httpStatus: $response->status(),
                requestStatus: 'failed',
                needsReview: false,
                reviewReasons: [],
                usage: $this->extractUsageMetrics($response->json()),
                errorCode: $this->responseErrorCode($response),
                errorMessage: $this->responseErrorMessage($response),
            );

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

        $responseJson = $response->json();

        $text = collect(Arr::get($responseJson, 'candidates', []))
            ->flatMap(fn (array $candidate) => Arr::get($candidate, 'content.parts', []))
            ->pluck('text')
            ->filter()
            ->implode("\n");

        if ($text === '') {
            $this->logGeminiAttempt(
                context: $context,
                model: $model,
                requestStartedAt: $requestStartedAt ?? Carbon::now(),
                requestFinishedAt: $requestFinishedAt ?? Carbon::now(),
                latencyMs: $latencyMs ?? 0,
                httpStatus: $response->status(),
                requestStatus: 'failed',
                needsReview: false,
                reviewReasons: [],
                usage: $this->extractUsageMetrics($responseJson),
                errorCode: 'empty_response',
                errorMessage: 'Gemini returned an empty extraction response.',
            );
            throw new RuntimeException('Gemini returned an empty extraction response.');
        }

        try {
            $decoded = $this->decodeJson($text);
        } catch (RuntimeException $exception) {
            $this->logGeminiAttempt(
                context: $context,
                model: $model,
                requestStartedAt: $requestStartedAt ?? Carbon::now(),
                requestFinishedAt: $requestFinishedAt ?? Carbon::now(),
                latencyMs: $latencyMs ?? 0,
                httpStatus: $response->status(),
                requestStatus: 'failed',
                needsReview: false,
                reviewReasons: [],
                usage: $this->extractUsageMetrics($responseJson),
                errorCode: 'invalid_json',
                errorMessage: $exception->getMessage(),
            );

            throw $exception;
        }

        $decoded['_raw_text'] = $text;

        $this->logGeminiAttempt(
            context: $context,
            model: $model,
            requestStartedAt: $requestStartedAt ?? Carbon::now(),
            requestFinishedAt: $requestFinishedAt ?? Carbon::now(),
            latencyMs: $latencyMs ?? 0,
            httpStatus: $response->status(),
            requestStatus: ($decoded['needs_review'] ?? false) ? 'review_required' : 'success',
            needsReview: (bool) ($decoded['needs_review'] ?? false),
            reviewReasons: is_array($decoded['review_reasons'] ?? null) ? $decoded['review_reasons'] : [],
            usage: $this->extractUsageMetrics($responseJson),
            documentType: data_get($decoded, 'classification.document_type'),
            errorCode: null,
            errorMessage: null,
        );

        return $decoded;
    }

    protected function shouldRetryResponse(Response $response): bool
    {
        return in_array($response->status(), [408, 429, 500, 502, 503, 504], true);
    }

    protected function shouldFallbackToNextModel(
        ConnectionException|RequestException|RuntimeException $exception,
    ): bool {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response?->status();

            if (in_array($status, [408, 429, 500, 502, 503, 504], true)) {
                return true;
            }

            if ($status === 404) {
                $message = strtolower($exception->getMessage());

                return str_contains($message, 'no longer available')
                    || str_contains($message, 'not found')
                    || str_contains($message, 'not supported')
                    || str_contains($message, 'not available');
            }

            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'high demand')
            || str_contains($message, 'temporarily overloaded')
            || str_contains($message, 'rate-limited')
            || str_contains($message, 'status code 429')
            || str_contains($message, 'status code 503');
    }

    protected function endpoint(string $model): string
    {
        $baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        $apiKey = config('services.gemini.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('Gemini API key is not configured.');
        }

        return "{$baseUrl}/models/{$model}:generateContent?key={$apiKey}";
    }

    protected function intakeRetryDelays(): array
    {
        $delays = config('services.gemini.intake_http_retry_delays_ms', [1000, 3000]);

        if (! is_array($delays) || $delays === []) {
            return [1000, 3000];
        }

        return array_values(array_filter(array_map(
            static fn ($value) => max(0, (int) $value),
            $delays,
        ), static fn ($value) => $value >= 0));
    }

    protected function logGeminiAttempt(
        array $context,
        string $model,
        Carbon $requestStartedAt,
        Carbon $requestFinishedAt,
        int $latencyMs,
        ?int $httpStatus,
        string $requestStatus,
        bool $needsReview,
        array $reviewReasons,
        array $usage,
        ?string $documentType = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): void {
        $this->aiUsageLogService->logRequest([
            'provider' => 'gemini',
            'request_context' => $context['request_context'] ?? 'document_extraction',
            'model' => $model,
            'lead_id' => $context['lead_id'] ?? null,
            'lead_document_id' => $context['lead_document_id'] ?? null,
            'document_type' => $documentType,
            'input_mime_type' => $context['input_mime_type'] ?? null,
            'input_filename' => $context['input_filename'] ?? null,
            'input_tokens' => $usage['input_tokens'] ?? null,
            'output_tokens' => $usage['output_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'latency_ms' => $latencyMs,
            'http_status' => $httpStatus,
            'request_status' => $requestStatus,
            'needs_review' => $needsReview,
            'review_reasons' => $reviewReasons,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'request_started_at' => $requestStartedAt,
            'request_finished_at' => $requestFinishedAt,
        ]);
    }

    protected function extractUsageMetrics(array $payload): array
    {
        return [
            'input_tokens' => $this->nullableInt(Arr::get($payload, 'usageMetadata.promptTokenCount')),
            'output_tokens' => $this->nullableInt(Arr::get($payload, 'usageMetadata.candidatesTokenCount')),
            'total_tokens' => $this->nullableInt(Arr::get($payload, 'usageMetadata.totalTokenCount')),
        ];
    }

    protected function responseErrorCode(Response $response): ?string
    {
        $status = Arr::get($response->json(), 'error.status');

        return is_string($status) && $status !== '' ? $status : null;
    }

    protected function responseErrorMessage(Response $response): ?string
    {
        $message = Arr::get($response->json(), 'error.message');

        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }

        $body = trim((string) $response->body());

        return $body !== '' ? $body : null;
    }

    protected function latencyMs(float $startedMicrotime): int
    {
        return max(0, (int) round((microtime(true) - $startedMicrotime) * 1000));
    }

    protected function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
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
        "document_type": "ic|payslip|pension_slip|epf|ramci|ctos|other",
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
- `document_type` must be one of: ic, payslip, pension_slip, epf, ramci, ctos, other.
- For IC, set `ic_side` to front or back when confident.
- IC front usually shows the person's name, IC number, and identity details such as date of birth.
- IC back should be recognized from stable reverse-side markers such as Touch 'n Go, "Ketua Pengarah Pendaftaran Negara", "Pendaftaran Negara", or other back-side printing. Do not depend on version-specific chip wording, and do not require an address to classify it as back.
- If the image looks like the blue patterned reverse side and the person's full name is not visible, prefer `ic_side = back` even when an address is absent.
- For payslip, set `statement_period` to YYYY-MM when confident. Also set `statement_year` and `statement_month`.
- Use `pension_slip` for pension statements or pension payment slips, including documents labeled with terms such as `pencen`, `pesara`, or retirement pension wording. Do not classify pension slips as `payslip`.
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
