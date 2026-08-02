<?php

namespace App\Services;

use App\Models\AiUsageLog;

class AiUsageLogService
{
    public function logRequest(array $attributes): AiUsageLog
    {
        $inputTokens = $this->nullableInt($attributes['input_tokens'] ?? null);
        $outputTokens = $this->nullableInt($attributes['output_tokens'] ?? null);
        $totalTokens = $this->nullableInt($attributes['total_tokens'] ?? null);

        if ($totalTokens === null && ($inputTokens !== null || $outputTokens !== null)) {
            $totalTokens = ($inputTokens ?? 0) + ($outputTokens ?? 0);
        }

        return AiUsageLog::create([
            'provider' => (string) ($attributes['provider'] ?? 'gemini'),
            'request_context' => (string) ($attributes['request_context'] ?? 'document_extraction'),
            'model' => (string) ($attributes['model'] ?? ''),
            'lead_id' => $this->nullableInt($attributes['lead_id'] ?? null),
            'lead_document_id' => $this->nullableInt($attributes['lead_document_id'] ?? null),
            'document_type' => $this->nullableString($attributes['document_type'] ?? null),
            'input_mime_type' => $this->nullableString($attributes['input_mime_type'] ?? null),
            'input_filename' => $this->nullableString($attributes['input_filename'] ?? null),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'estimated_cost' => $attributes['estimated_cost'] ?? $this->estimateCost(
                (string) ($attributes['provider'] ?? 'gemini'),
                (string) ($attributes['model'] ?? ''),
                $inputTokens,
                $outputTokens,
            ),
            'latency_ms' => max(0, (int) ($attributes['latency_ms'] ?? 0)),
            'http_status' => $this->nullableInt($attributes['http_status'] ?? null),
            'request_status' => (string) ($attributes['request_status'] ?? 'success'),
            'needs_review' => (bool) ($attributes['needs_review'] ?? false),
            'review_reasons' => array_values(array_unique(array_filter(
                is_array($attributes['review_reasons'] ?? null) ? $attributes['review_reasons'] : []
            ))),
            'error_code' => $this->nullableString($attributes['error_code'] ?? null),
            'error_message' => $this->nullableString($attributes['error_message'] ?? null),
            'request_started_at' => $attributes['request_started_at'] ?? now(),
            'request_finished_at' => $attributes['request_finished_at'] ?? now(),
        ]);
    }

    public function estimateCost(string $provider, string $model, ?int $inputTokens, ?int $outputTokens): ?float
    {
        if ($provider !== 'gemini' || $model === '') {
            return null;
        }

        $models = config('services.ai_monitoring.providers.gemini.models', []);
        $pricing = is_array($models) ? ($models[$model] ?? null) : null;
        if (! is_array($pricing)) {
            return null;
        }

        $inputPrice = $pricing['input_per_million_tokens'] ?? null;
        $outputPrice = $pricing['output_per_million_tokens'] ?? null;

        if (! is_numeric($inputPrice) || ! is_numeric($outputPrice)) {
            return null;
        }

        if ($inputTokens === null && $outputTokens === null) {
            return null;
        }

        $estimated = (($inputTokens ?? 0) / 1_000_000) * (float) $inputPrice
            + (($outputTokens ?? 0) / 1_000_000) * (float) $outputPrice;

        return round($estimated, 6);
    }

    protected function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }
}
