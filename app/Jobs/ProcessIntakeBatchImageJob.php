<?php

namespace App\Jobs;

use App\Models\IntakeBatchImage;
use App\Services\IntakeBatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessIntakeBatchImageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $imageId,
    ) {
        $this->onQueue(config('queue.workloads.intake', 'intake'));
    }

    public function handle(IntakeBatchService $intakeBatchService): void
    {
        $image = IntakeBatchImage::query()
            ->with('batch')
            ->find($this->imageId);

        if (! $image || ! $image->batch) {
            return;
        }

        $intakeBatchService->prepareImageForProcessing($image);
    }
}