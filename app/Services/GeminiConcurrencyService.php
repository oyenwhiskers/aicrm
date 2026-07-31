<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class GeminiConcurrencyService
{
    public function acquireGlobal(?int $limit = null, ?int $leaseSeconds = null): ?Lock
    {
        return $this->acquireScopedSlot(
            'gemini:global',
            $limit ?? $this->globalConcurrency(),
            $leaseSeconds ?? $this->leaseSeconds(),
        );
    }

    public function acquireScopedSlot(string $prefix, int $limit, ?int $leaseSeconds = null): ?Lock
    {
        $effectiveLeaseSeconds = $leaseSeconds ?? $this->leaseSeconds();

        for ($slot = 1; $slot <= max(1, $limit); $slot++) {
            $lock = Cache::lock("{$prefix}:{$slot}", $effectiveLeaseSeconds);

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    public function release(mixed $lease): void
    {
        if ($lease instanceof Lock) {
            try {
                $lease->release();
            } catch (\Throwable) {
            }

            return;
        }

        if (! is_array($lease)) {
            return;
        }

        foreach ($lease as $value) {
            $this->release($value);
        }
    }

    public function globalConcurrency(): int
    {
        return max(1, (int) config('services.gemini.global_concurrency', 2));
    }

    public function leaseSeconds(): int
    {
        return max(30, (int) config('services.gemini.slot_lease_seconds', 240));
    }

    public function slotRequeueDelaySeconds(): int
    {
        return max(1, (int) config('services.gemini.slot_requeue_seconds', 3));
    }
}
