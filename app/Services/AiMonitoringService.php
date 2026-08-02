<?php

namespace App\Services;

use App\Models\AiUsageLog;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AiMonitoringService
{
    public function normalizedFilters(array $filters): array
    {
        $defaultDays = max(1, (int) config('services.ai_monitoring.default_overview_days', 7));
        $today = now()->endOfDay();
        $defaultFrom = now()->subDays($defaultDays - 1)->startOfDay();

        $dateFrom = $this->parseDate($filters['date_from'] ?? null, $defaultFrom)?->startOfDay() ?? $defaultFrom;
        $dateTo = $this->parseDate($filters['date_to'] ?? null, $today)?->endOfDay() ?? $today;

        if ($dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo->copy()->startOfDay(), $dateFrom->copy()->endOfDay()];
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'request_context' => $this->nullableString($filters['request_context'] ?? null),
            'model' => $this->nullableString($filters['model'] ?? null),
            'document_type' => $this->nullableString($filters['document_type'] ?? null),
            'request_status' => $this->nullableString($filters['request_status'] ?? null),
            'needs_review' => $this->nullableBoolean($filters['needs_review'] ?? null),
            'lead_id' => $this->nullableInt($filters['lead_id'] ?? null),
        ];
    }

    public function overview(array $filters): array
    {
        $query = $this->query($filters);
        $row = (clone $query)
            ->selectRaw('COUNT(*) as total_requests')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('SUM(estimated_cost) as estimated_cost')
            ->selectRaw('AVG(latency_ms) as average_latency_ms')
            ->selectRaw("SUM(CASE WHEN request_status = 'failed' THEN 1 ELSE 0 END) as failed_requests")
            ->selectRaw("SUM(CASE WHEN needs_review = 1 THEN 1 ELSE 0 END) as review_required_requests")
            ->first();

        $total = (int) ($row->total_requests ?? 0);
        $reviewCount = (int) ($row->review_required_requests ?? 0);
        $failedCount = (int) ($row->failed_requests ?? 0);

        return [
            'filters' => $this->serializeFilters($filters),
            'totals' => [
                'total_requests' => $total,
                'input_tokens' => (int) ($row->input_tokens ?? 0),
                'output_tokens' => (int) ($row->output_tokens ?? 0),
                'total_tokens' => (int) ($row->total_tokens ?? 0),
                'estimated_cost' => $row->estimated_cost !== null ? round((float) $row->estimated_cost, 6) : null,
                'average_latency_ms' => $row->average_latency_ms !== null ? (int) round((float) $row->average_latency_ms) : 0,
                'failed_requests' => $failedCount,
                'review_required_requests' => $reviewCount,
                'success_requests' => max(0, $total - $failedCount),
                'review_required_rate' => $total > 0 ? round(($reviewCount / $total) * 100, 1) : 0.0,
            ],
        ];
    }

    public function breakdowns(array $filters): array
    {
        return [
            'filters' => $this->serializeFilters($filters),
            'by_context' => $this->groupedBreakdown($filters, 'request_context'),
            'by_model' => $this->groupedBreakdown($filters, 'model'),
            'by_document_type' => $this->groupedBreakdown($filters, 'document_type', '(none)'),
        ];
    }

    public function requests(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($filters)
            ->latest('request_started_at')
            ->paginate(min(max($perPage, 1), 100))
            ->through(fn (AiUsageLog $log) => [
                'id' => $log->id,
                'provider' => $log->provider,
                'request_context' => $log->request_context,
                'model' => $log->model,
                'lead_id' => $log->lead_id,
                'lead_document_id' => $log->lead_document_id,
                'document_type' => $log->document_type,
                'input_mime_type' => $log->input_mime_type,
                'input_filename' => $log->input_filename,
                'input_tokens' => $log->input_tokens,
                'output_tokens' => $log->output_tokens,
                'total_tokens' => $log->total_tokens,
                'estimated_cost' => $log->estimated_cost !== null ? (float) $log->estimated_cost : null,
                'latency_ms' => $log->latency_ms,
                'http_status' => $log->http_status,
                'request_status' => $log->request_status,
                'needs_review' => $log->needs_review,
                'review_reasons' => $log->review_reasons ?? [],
                'error_code' => $log->error_code,
                'error_message' => $log->error_message,
                'request_started_at' => $log->request_started_at?->toIso8601String(),
                'request_finished_at' => $log->request_finished_at?->toIso8601String(),
                'created_at' => $log->created_at?->toIso8601String(),
            ]);
    }

    public function deleteAllLogs(): int
    {
        return AiUsageLog::query()->delete();
    }

    protected function groupedBreakdown(array $filters, string $column, string $nullLabel = '(unknown)'): array
    {
        return (clone $this->query($filters))
            ->selectRaw("COALESCE({$column}, ?) as dimension", [$nullLabel])
            ->selectRaw('COUNT(*) as total_requests')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('SUM(estimated_cost) as estimated_cost')
            ->selectRaw('AVG(latency_ms) as average_latency_ms')
            ->selectRaw("SUM(CASE WHEN request_status = 'failed' THEN 1 ELSE 0 END) as failed_requests")
            ->selectRaw("SUM(CASE WHEN needs_review = 1 THEN 1 ELSE 0 END) as review_required_requests")
            ->groupBy('dimension')
            ->orderByDesc('total_requests')
            ->get()
            ->map(fn ($row) => [
                'dimension' => (string) $row->dimension,
                'total_requests' => (int) $row->total_requests,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'total_tokens' => (int) $row->total_tokens,
                'estimated_cost' => $row->estimated_cost !== null ? round((float) $row->estimated_cost, 6) : null,
                'average_latency_ms' => $row->average_latency_ms !== null ? (int) round((float) $row->average_latency_ms) : 0,
                'failed_requests' => (int) $row->failed_requests,
                'review_required_requests' => (int) $row->review_required_requests,
                'review_required_rate' => (int) $row->total_requests > 0
                    ? round(((int) $row->review_required_requests / (int) $row->total_requests) * 100, 1)
                    : 0.0,
            ])
            ->values()
            ->all();
    }

    protected function query(array $filters): Builder
    {
        return AiUsageLog::query()
            ->where('provider', 'gemini')
            ->whereBetween('request_started_at', [$filters['date_from'], $filters['date_to']])
            ->when($filters['request_context'], fn (Builder $query, string $value) => $query->where('request_context', $value))
            ->when($filters['model'], fn (Builder $query, string $value) => $query->where('model', $value))
            ->when($filters['document_type'], fn (Builder $query, string $value) => $query->where('document_type', $value))
            ->when($filters['request_status'], fn (Builder $query, string $value) => $query->where('request_status', $value))
            ->when($filters['needs_review'] !== null, fn (Builder $query) => $query->where('needs_review', $filters['needs_review']))
            ->when($filters['lead_id'], fn (Builder $query, int $value) => $query->where('lead_id', $value));
    }

    protected function serializeFilters(array $filters): array
    {
        return [
            'date_from' => $filters['date_from']->toDateString(),
            'date_to' => $filters['date_to']->toDateString(),
            'request_context' => $filters['request_context'],
            'model' => $filters['model'],
            'document_type' => $filters['document_type'],
            'request_status' => $filters['request_status'],
            'needs_review' => $filters['needs_review'],
            'lead_id' => $filters['lead_id'],
        ];
    }

    protected function parseDate(mixed $value, Carbon $fallback): ?Carbon
    {
        $normalized = $this->nullableString($value);

        if ($normalized === null) {
            return $fallback->copy();
        }

        try {
            return Carbon::parse($normalized);
        } catch (\Throwable) {
            return $fallback->copy();
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    protected function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => null,
        };
    }

    protected function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
