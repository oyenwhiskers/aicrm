<?php

namespace App\Jobs;

use App\Models\IntakeBatch;
use App\Models\IntakeBatchImage;
use App\Services\IntakeBatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AggregateIntakeBatchJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $batchId,
        public ?int $imageId = null,
    ) {
        $this->onQueue(config('queue.workloads.intake', 'intake'));
    }

    public function handle(IntakeBatchService $intakeBatchService): void
    {
        $batch = IntakeBatch::query()->find($this->batchId);

        if (! $batch) {
            return;
        }

        $image = $this->imageId
            ? IntakeBatchImage::query()->with('batch')->find($this->imageId)
            : null;

        $intakeBatchService->aggregateBatch($batch, $image);
    }
}