<?php

namespace App\Services;

use App\Enums\IntakeBatchImageStatus;
use App\Enums\IntakeBatchStatus;
use App\Models\IntakeBatch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class IntakeBatchAggregationService
{
    public function normalizeBatchRows(IntakeBatch $batch): void
    {
        $rows = $batch->extractedRows()
            ->with('image:id,original_filename')
            ->get()
            ->groupBy('phone_number');

        $normalizedRows = $rows->map(function (Collection $group) {
            $best = $group->sortByDesc(function ($row) {
                return [
                    $this->confidenceScore($row->confidence),
                    mb_strlen((string) $row->name),
                ];
            })->first();

            return [
                'intake_batch_id' => $best->intake_batch_id,
                'name' => $best->name,
                'phone_number' => $best->phone_number,
                'source' => $best->source,
                'confidence' => $best->confidence,
                'notes' => $best->notes,
                'source_images' => $group
                    ->map(fn ($row) => [
                        'image_id' => $row->intake_batch_image_id,
                        'filename' => $row->image?->original_filename,
                    ])
                    ->unique('image_id')
                    ->values()
                    ->all(),
                'metadata' => [
                    'raw_row_count' => $group->count(),
                    'name_variants' => $group->pluck('raw_name')->filter()->unique()->values()->all(),
                ],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->values()->all();

        DB::transaction(function () use ($batch, $normalizedRows) {
            IntakeBatch::query()
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->first();

            $batch->normalizedRows()->delete();

            if ($normalizedRows !== []) {
                $batch->normalizedRows()->createMany($normalizedRows);
            }
        }, 3);
    }

    public function refreshBatchProgress(IntakeBatch $batch): void
    {
        $counts = $batch->images()
            ->selectRaw('status, COUNT(*) as aggregate_count')
            ->groupBy('status')
            ->pluck('aggregate_count', 'status');

        $queued = (int) ($counts[IntakeBatchImageStatus::QUEUED->value] ?? 0)
            + (int) ($counts[IntakeBatchImageStatus::RETRY_PENDING->value] ?? 0);
        $processing = (int) ($counts[IntakeBatchImageStatus::PROCESSING->value] ?? 0);
        $successful = (int) ($counts[IntakeBatchImageStatus::DONE->value] ?? 0);
        $failed = (int) ($counts[IntakeBatchImageStatus::FAILED->value] ?? 0);
        $processed = $successful + $failed;

        $status = match (true) {
            $processed >= $batch->total_images && $successful > 0 && $failed > 0 => IntakeBatchStatus::COMPLETED_WITH_FAILURES,
            $processed >= $batch->total_images && $successful > 0 => IntakeBatchStatus::COMPLETED,
            $processed >= $batch->total_images && $failed > 0 => IntakeBatchStatus::FAILED,
            $processing > 0 || ($processed > 0 && $queued > 0) => IntakeBatchStatus::PROCESSING,
            default => IntakeBatchStatus::QUEUED,
        };

        $batch->forceFill([
            'status' => $status,
            'processed_images' => $processed,
            'successful_images' => $successful,
            'failed_images' => $failed,
            'total_rows' => $batch->normalizedRows()->count(),
            'started_at' => $batch->started_at ?? ($processing > 0 || $processed > 0 ? now() : null),
            'completed_at' => $processed >= $batch->total_images ? now() : null,
        ])->save();
    }

    protected function confidenceScore(?string $confidence): int
    {
        return match ($confidence) {
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }
}