<?php

namespace App\Services;

use App\Models\IntakeImageAttempt;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class IntakeGeminiConcurrencyService
{
    public function acquire(int $batchId): ?array
    {
        $leaseSeconds = max(30, (int) config('services.gemini.intake_slot_lease_seconds', 240));
        $globalLimit = $this->effectiveGlobalConcurrency();
        $batchLimit = max(1, min(
            (int) config('services.gemini.intake_per_batch_concurrency', 2),
            $globalLimit,
        ));

        $globalLock = $this->acquireSlotLock('intake-gemini:global', $globalLimit, $leaseSeconds);

        if (! $globalLock) {
            return null;
        }

        $batchLock = $this->acquireSlotLock("intake-gemini:batch:{$batchId}", $batchLimit, $leaseSeconds);

        if (! $batchLock) {
            $globalLock->release();

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
        if (! is_array($lease)) {
            return;
        }

        foreach (['batch', 'global'] as $key) {
            $lock = $lease[$key] ?? null;

            if (! $lock instanceof Lock) {
                continue;
            }

            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }
    }

    public function slotRequeueDelaySeconds(): int
    {
        return max(1, (int) config('services.gemini.intake_slot_requeue_seconds', 3));
    }

    protected function acquireSlotLock(string $prefix, int $limit, int $leaseSeconds): ?Lock
    {
        for ($slot = 1; $slot <= max(1, $limit); $slot++) {
            $lock = Cache::lock("{$prefix}:{$slot}", $leaseSeconds);

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    protected function effectiveGlobalConcurrency(): int
    {
        $base = max(1, (int) config('services.gemini.intake_global_concurrency', 2));
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