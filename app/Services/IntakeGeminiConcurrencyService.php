<?php

namespace App\Services;

use App\Models\IntakeImageAttempt;

class IntakeGeminiConcurrencyService
{
    public function __construct(
        protected GeminiConcurrencyService $geminiConcurrencyService,
    ) {
    }

    public function acquire(int $batchId): ?array
    {
        $leaseSeconds = $this->geminiConcurrencyService->leaseSeconds();
        $globalLimit = $this->effectiveGlobalConcurrency();
        $batchLimit = max(1, min(
            (int) config('services.gemini.intake_per_batch_concurrency', 2),
            $globalLimit,
        ));

        $globalLock = $this->geminiConcurrencyService->acquireGlobal($globalLimit, $leaseSeconds);

        if (! $globalLock) {
            return null;
        }

        $batchLock = $this->geminiConcurrencyService->acquireScopedSlot("intake-gemini:batch:{$batchId}", $batchLimit, $leaseSeconds);

        if (! $batchLock) {
            $this->geminiConcurrencyService->release($globalLock);

            return null;
        }

        return [
            'global' => $globalLock,
            'batch' => $batchLock,
            'effective_global_limit' => $globalLimit,
            'effective_batch_limit' => $batchLimit,
        ];
    }

    public function release(?array $lease): void
    {
        $this->geminiConcurrencyService->release($lease);
    }

    public function slotRequeueDelaySeconds(): int
    {
        return $this->geminiConcurrencyService->slotRequeueDelaySeconds();
    }

    protected function effectiveGlobalConcurrency(): int
    {
        $base = $this->geminiConcurrencyService->globalConcurrency();
        $minimum = max(1, min(
            (int) config('services.gemini.intake_adaptive_min_concurrency', 1),
            $base,
        ));
        $windowSeconds = max(30, (int) config('services.gemini.intake_adaptive_window_seconds', 180));
        $overloadThreshold = max(1, (int) config('services.gemini.intake_adaptive_overload_threshold', 3));

        $recentAttempts = IntakeImageAttempt::query()
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', now()->subSeconds($windowSeconds))
            ->get(['error_type', 'error_message']);

        $totalAttempts = $recentAttempts->count();

        if ($totalAttempts === 0) {
            return $base;
        }

        $overloadAttempts = $recentAttempts
            ->filter(fn (IntakeImageAttempt $attempt) => $this->isOverloadAttempt($attempt->error_type, $attempt->error_message))
            ->count();

        if ($overloadAttempts < $overloadThreshold) {
            return $base;
        }

        $overloadRate = $overloadAttempts / max(1, $totalAttempts);

        return $overloadRate >= 0.35 ? $minimum : $base;
    }

    protected function isOverloadAttempt(?string $errorType, ?string $errorMessage): bool
    {
        if ($errorType !== 'RequestException' || blank($errorMessage)) {
            return false;
        }

        $message = strtolower($errorMessage);

        return str_contains($message, 'status code 429')
            || str_contains($message, 'status code 503')
            || str_contains($message, '"code": 429')
            || str_contains($message, '"code": 503')
            || str_contains($message, 'high demand')
            || str_contains($message, 'rate limit');
    }
}
