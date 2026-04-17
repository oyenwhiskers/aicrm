<?php

namespace App\Services;

use App\Enums\IntakeBatchImageStatus;
use App\Enums\IntakeBatchStatus;
use App\Jobs\AggregateIntakeBatchJob;
use App\Jobs\ProcessIntakeBatchImageJob;
use App\Jobs\ProcessIntakeBatchImageAiJob;
use App\Models\IntakeBatch;
use App\Models\IntakeBatchImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class IntakeBatchService
{
    public function __construct(
        protected IntakeImagePreprocessService $intakeImagePreprocessService,
        protected IntakeImageExtractionService $intakeImageExtractionService,
        protected IntakeGeminiConcurrencyService $intakeGeminiConcurrencyService,
        protected IntakeBatchAggregationService $intakeBatchAggregationService,
        protected IntakeImageStageService $intakeImageStageService,
    ) {
    }

    public function createBatch(array $images, ?string $source = null, array $clientKeys = [], array $imageMetadata = []): IntakeBatch
    {
        $batch = DB::transaction(function () use ($images, $source, $clientKeys, $imageMetadata) {
            $batch = IntakeBatch::query()->create([
                'source' => $source ?: 'image extraction',
                'status' => IntakeBatchStatus::QUEUED,
                'total_images' => count($images),
                'metadata' => [
                    'model_name' => config('services.gemini.intake_model', config('services.gemini.model')),
                    'prompt_version' => 'lead_capture_v1',
                ],
            ]);

            collect($images)->values()->each(function (UploadedFile $image, int $index) use ($batch, $clientKeys, $imageMetadata) {
                $path = $image->store("intake-batches/{$batch->id}", 'public');
                $preprocessMetadata = $this->intakeImagePreprocessService->buildMetadata(
                    $image,
                    $this->decodeImageMetadata($imageMetadata[$index] ?? null),
                );

                $batch->images()->create([
                    'original_filename' => $image->getClientOriginalName(),
                    'storage_disk' => 'public',
                    'storage_path' => $path,
                    'sort_order' => $index,
                    'status' => IntakeBatchImageStatus::QUEUED,
                    'metadata' => $this->intakeImageStageService->initialMetadata([
                        'mime_type' => $image->getMimeType(),
                        'size' => $image->getSize(),
                        'client_key' => $clientKeys[$index] ?? null,
                        'preprocess' => $preprocessMetadata,
                    ]),
                ]);
            });

            return $batch->load('images');
        });

        $batch->images
            ->sortBy('sort_order')
            ->each(fn (IntakeBatchImage $image) => ProcessIntakeBatchImageJob::dispatch($image->id));

        return $batch;
    }

    public function prepareImageForProcessing(IntakeBatchImage $image): void
    {
        $image = IntakeBatchImage::query()
            ->with('batch')
            ->find($image->id);

        if (! $image || ! $image->batch) {
            return;
        }

        if (in_array($image->status, [IntakeBatchImageStatus::DONE, IntakeBatchImageStatus::FAILED], true)) {
            return;
        }

        $image = $this->intakeImageStageService->markStage($image, 'preprocess', 'completed', [
            'strategy' => data_get($image->metadata, 'preprocess.strategy'),
            'prepared_at' => data_get($image->metadata, 'preprocess.prepared_at'),
            'received_at' => data_get($image->metadata, 'preprocess.received_at'),
        ]);

        $this->dispatchAiStage($image->id);
    }

    public function processImage(IntakeBatchImage $image): void
    {
        $claimToken = (string) Str::uuid();
        $claimedBy = $this->workerIdentity();
        $claimedAt = now();
        $semaphoreLease = null;

        if (! $this->claimImage($image->id, $claimToken, $claimedBy, $claimedAt)) {
            Log::info('claim_rejected', [
                'event' => 'claim_rejected',
                'image_id' => $image->id,
                'batch_id' => $image->intake_batch_id,
                'claim_token' => $claimToken,
                'claimed_by' => $claimedBy,
            ]);

            return;
        }

        $image = IntakeBatchImage::query()
            ->with('batch')
            ->find($image->id);

        if (! $image || ! $image->batch) {
            return;
        }

        $batch = $image->batch;
        $attemptNumber = (int) $image->attempts_count;

        Log::info('claim_acquired', [
            'event' => 'claim_acquired',
            'image_id' => $image->id,
            'batch_id' => $batch->id,
            'attempt_no' => $attemptNumber,
            'claim_token' => $claimToken,
            'claimed_by' => $claimedBy,
            'status' => $image->status->value,
        ]);

        if ($batch->started_at === null) {
            $batch->forceFill([
                'started_at' => now(),
                'status' => IntakeBatchStatus::PROCESSING,
            ])->save();
        }

        $semaphoreLease = $this->intakeGeminiConcurrencyService->acquire($batch->id);

        if (! $semaphoreLease) {
            $this->intakeImageStageService->markStage($image, 'waiting_for_ai_slot', 'waiting', [
                'reason' => 'gemini_slot_unavailable',
            ]);

            $this->releaseClaimForSlotWait($image->id, $claimToken);

            $this->dispatchAiStage(
                $image->id,
                $this->intakeGeminiConcurrencyService->slotRequeueDelaySeconds(),
            );

            Log::info('gemini_slot_unavailable', [
                'event' => 'gemini_slot_unavailable',
                'image_id' => $image->id,
                'batch_id' => $batch->id,
                'claim_token' => $claimToken,
                'claimed_by' => $claimedBy,
            ]);

            $this->dispatchAggregationStage($batch->id, $image->id);

            return;
        }

        if (! $this->beginProcessingAttempt($image->id, $claimToken)) {
            $this->intakeGeminiConcurrencyService->release($semaphoreLease);

            return;
        }

        $image = IntakeBatchImage::query()
            ->with('batch')
            ->find($image->id);

        if (! $image || ! $image->batch) {
            $this->intakeGeminiConcurrencyService->release($semaphoreLease);

            return;
        }

        $image = $this->intakeImageStageService->markStage($image, 'ai_processing', 'active', [
            'attempt_no' => (int) $image->attempts_count,
        ]);

        $attempt = $image->attempts()->create([
            'attempt_no' => (int) $image->attempts_count,
            'status' => IntakeBatchImageStatus::PROCESSING->value,
            'model_name' => config('services.gemini.intake_model', config('services.gemini.model')),
            'prompt_version' => 'lead_capture_v1',
            'started_at' => now(),
        ]);

        $attemptNumber = (int) $image->attempts_count;

        try {
            $extraction = $this->intakeImageExtractionService->extractAndStore($image, $batch);
            $result = $extraction['result'];
            $metadata = $this->intakeImageStageService->mergedMetadata($image, $extraction['metadata']);
            $rowCount = $extraction['row_count'];

            $finalized = $this->finalizeImage(
                $image->id,
                $claimToken,
                [
                    'status' => IntakeBatchImageStatus::DONE,
                    'row_count' => $rowCount,
                    'last_error' => null,
                    'completed_at' => now(),
                    'metadata' => $metadata,
                ],
            );

            if (! $finalized) {
                Log::warning('stale_finalize_rejected', [
                    'event' => 'stale_finalize_rejected',
                    'image_id' => $image->id,
                    'batch_id' => $batch->id,
                    'attempt_no' => $attemptNumber,
                    'claim_token' => $claimToken,
                    'claimed_by' => $claimedBy,
                    'result' => 'success',
                ]);

                $attempt->forceFill([
                    'status' => IntakeBatchImageStatus::FAILED->value,
                    'error_type' => 'stale_finalize_rejected',
                    'error_message' => 'Finalize skipped because claim ownership no longer matched.',
                    'finished_at' => now(),
                ])->save();

                $this->dispatchAggregationStage($batch->id, $image->id);

                return;
            }

            $attempt->forceFill([
                'status' => IntakeBatchImageStatus::DONE->value,
                'raw_response' => $result,
                'finished_at' => now(),
            ])->save();

            Log::info('finalize_success', [
                'event' => 'finalize_success',
                'image_id' => $image->id,
                'batch_id' => $batch->id,
                'attempt_no' => $attemptNumber,
                'claim_token' => $claimToken,
                'claimed_by' => $claimedBy,
                'final_status' => IntakeBatchImageStatus::DONE->value,
                'row_count' => $rowCount,
            ]);

            $image = $this->intakeImageStageService->markStage($image->fresh(), 'aggregating', 'queued', [
                'result' => 'success',
                'row_count' => $rowCount,
            ]);

            $this->dispatchAggregationStage($batch->id, $image->id);
        } catch (\Throwable $exception) {
            $retryDecision = $this->intakeRetryDecision($exception, $attemptNumber);
            $targetStatus = $retryDecision['should_retry']
                ? IntakeBatchImageStatus::RETRY_PENDING
                : IntakeBatchImageStatus::FAILED;
            $retryScheduled = $retryDecision['should_retry'];

            $finalized = $this->finalizeImage(
                $image->id,
                $claimToken,
                [
                    'status' => $targetStatus,
                    'last_error' => $exception->getMessage(),
                    'completed_at' => $retryScheduled ? null : now(),
                ],
            );

            if (! $finalized) {
                Log::warning('stale_finalize_rejected', [
                    'event' => 'stale_finalize_rejected',
                    'image_id' => $image->id,
                    'batch_id' => $batch->id,
                    'attempt_no' => $attemptNumber,
                    'claim_token' => $claimToken,
                    'claimed_by' => $claimedBy,
                    'result' => 'failure',
                ]);

                $attempt->forceFill([
                    'status' => IntakeBatchImageStatus::FAILED->value,
                    'error_type' => 'stale_finalize_rejected',
                    'error_message' => 'Finalize skipped because claim ownership no longer matched.',
                    'finished_at' => now(),
                ])->save();

                $this->dispatchAggregationStage($batch->id, $image->id);

                return;
            }

            $attempt->forceFill([
                'status' => $targetStatus->value,
                'error_type' => class_basename($exception),
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            if ($retryScheduled) {
                $this->dispatchAiStage($image->id, $retryDecision['delay_seconds']);
            }

            Log::info('finalize_success', [
                'event' => 'finalize_success',
                'image_id' => $image->id,
                'batch_id' => $batch->id,
                'attempt_no' => $attemptNumber,
                'claim_token' => $claimToken,
                'claimed_by' => $claimedBy,
                'final_status' => $targetStatus->value,
                'error_type' => class_basename($exception),
                'retry_scheduled' => $retryScheduled,
            ]);

            $image = $this->intakeImageStageService->markStage(
                $image->fresh(),
                $retryScheduled ? 'retry_pending' : 'failed',
                $retryScheduled ? 'retry_pending' : 'failed',
                [
                    'error_type' => class_basename($exception),
                ],
            );

            $this->dispatchAggregationStage($batch->id, $image->id);
        } finally {
            $this->intakeGeminiConcurrencyService->release($semaphoreLease);
        }
    }

    public function aggregateBatch(IntakeBatch $batch, ?IntakeBatchImage $image = null): void
    {
        $batch = $batch->fresh();

        if (! $batch) {
            return;
        }

        if ($image && $image->intake_batch_id === $batch->id && $image->status === IntakeBatchImageStatus::DONE) {
            $image = $this->intakeImageStageService->markStage($image->fresh(), 'aggregating', 'active', [
                'result' => 'success',
                'row_count' => (int) ($image->row_count ?? 0),
            ]);
        }

        $this->intakeBatchAggregationService->normalizeBatchRows($batch);
        $this->intakeBatchAggregationService->refreshBatchProgress($batch->fresh());

        if (! $image) {
            return;
        }

        $image = $image->fresh();

        if (($image->status ?? null) === IntakeBatchImageStatus::DONE) {
            $this->intakeImageStageService->markStage($image, 'completed', 'completed', [
                'row_count' => (int) ($image->row_count ?? 0),
            ]);
        }
    }

    public function statusPayload(IntakeBatch $batch): array
    {
        $batch->loadMissing([
            'images' => fn ($query) => $query->orderBy('sort_order'),
            'normalizedRows' => fn ($query) => $query->orderBy('phone_number'),
        ]);

        $snapshotTime = now();

        return [
            'id' => $batch->id,
            'source' => $batch->source,
            'status' => $batch->status->value,
            'total_images' => $batch->total_images,
            'processed_images' => $batch->processed_images,
            'successful_images' => $batch->successful_images,
            'failed_images' => $batch->failed_images,
            'total_rows' => $batch->total_rows,
            'created_at' => $batch->created_at?->toIso8601String(),
            'started_at' => $batch->started_at?->toIso8601String(),
            'completed_at' => $batch->completed_at?->toIso8601String(),
            'images' => $batch->images->map(fn (IntakeBatchImage $image) => [
                'id' => $image->id,
                'original_filename' => $image->original_filename,
                'sort_order' => $image->sort_order,
                'status' => $image->status->value,
                'row_count' => $image->row_count,
                'last_error' => $image->last_error,
                'client_key' => data_get($image->metadata, 'client_key'),
                'attempts_count' => (int) $image->attempts_count,
                'claimed_by' => $image->claimed_by,
                'created_at' => $image->created_at?->toIso8601String(),
                'started_at' => $image->started_at?->toIso8601String(),
                'completed_at' => $image->completed_at?->toIso8601String(),
                'preprocess' => data_get($image->metadata, 'preprocess'),
                'pipeline' => $this->intakeImageStageService->stagePayload($image),
                'timing' => $this->imageTimingPayload($image, $snapshotTime),
            ])->values(),
            'rows' => $batch->normalizedRows->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'phone_number' => $row->phone_number,
                'source' => $row->source,
                'confidence' => $row->confidence,
                'notes' => $row->notes,
                'source_images' => $row->source_images,
            ])->values(),
            'performance' => $this->batchPerformancePayload($batch, $snapshotTime),
        ];
    }

    protected function batchPerformancePayload(IntakeBatch $batch, $snapshotTime): array
    {
        $images = $batch->images;
        $totals = [
            'queue_wait_seconds' => [],
            'processing_seconds' => [],
            'total_elapsed_seconds' => [],
            'ai_slot_wait_seconds' => [],
            'ai_processing_seconds' => [],
            'aggregation_seconds' => [],
            'transfer_saved_bytes' => [],
        ];
        $workerIds = [];
        $retriedImages = 0;
        $aiSlotWaitImages = 0;

        foreach ($images as $image) {
            $timing = $this->imageTimingPayload($image, $snapshotTime);
            $pipeline = $this->intakeImageStageService->stagePayload($image);
            $preprocess = data_get($image->metadata, 'preprocess', []);

            $this->pushMetric($totals['queue_wait_seconds'], $timing['queue_wait_seconds'] ?? null);
            $this->pushMetric($totals['processing_seconds'], $timing['processing_seconds'] ?? null);
            $this->pushMetric($totals['total_elapsed_seconds'], $timing['total_elapsed_seconds'] ?? null);
            $this->pushMetric($totals['ai_slot_wait_seconds'], data_get($pipeline, 'stages.waiting_for_ai_slot.elapsed_seconds'));
            $this->pushMetric($totals['ai_processing_seconds'], data_get($pipeline, 'stages.ai_processing.elapsed_seconds'));
            $this->pushMetric($totals['aggregation_seconds'], data_get($pipeline, 'stages.aggregating.elapsed_seconds'));
            $this->pushMetric($totals['transfer_saved_bytes'], data_get($preprocess, 'transfer_saved_bytes'));

            if ($image->attempts_count > 1) {
                $retriedImages++;
            }

            if (data_get($pipeline, 'stages.waiting_for_ai_slot')) {
                $aiSlotWaitImages++;
            }

            if ($image->claimed_by) {
                $workerIds[$image->claimed_by] = true;
            }
        }

        $processedImages = max(0, (int) $batch->processed_images);
        $totalImages = max(0, (int) $batch->total_images);
        $totalElapsedSeconds = $this->secondsBetween($batch->created_at, $batch->completed_at ?: $snapshotTime);
        $imagesPerMinute = $totalElapsedSeconds && $processedImages > 0
            ? round(($processedImages / max(1, $totalElapsedSeconds)) * 60, 2)
            : null;
        $distinctWorkers = count($workerIds);
        $imagesPerWorker = $totalImages > 0
            ? round($totalImages / max(1, $distinctWorkers ?: 1), 2)
            : null;
        $summary = [
            'total_images' => $totalImages,
            'total_elapsed_seconds' => $totalElapsedSeconds,
            'avg_queue_wait_seconds' => $this->averageMetric($totals['queue_wait_seconds']),
            'avg_processing_seconds' => $this->averageMetric($totals['processing_seconds']),
            'avg_total_elapsed_seconds' => $this->averageMetric($totals['total_elapsed_seconds']),
            'avg_ai_slot_wait_seconds' => $this->averageMetric($totals['ai_slot_wait_seconds']),
            'avg_ai_processing_seconds' => $this->averageMetric($totals['ai_processing_seconds']),
            'avg_aggregation_seconds' => $this->averageMetric($totals['aggregation_seconds']),
            'avg_transfer_saved_bytes' => $this->averageMetric($totals['transfer_saved_bytes']),
            'retried_images' => $retriedImages,
            'ai_slot_wait_images' => $aiSlotWaitImages,
            'distinct_workers' => $distinctWorkers,
            'images_per_worker' => $imagesPerWorker,
            'serial_batch_processing' => $distinctWorkers <= 1 && $totalImages > 1,
            'images_per_minute' => $imagesPerMinute,
        ];

        $dominantStage = $this->dominantInfrastructureStage($summary);

        return [
            ...$summary,
            'dominant_stage' => $dominantStage,
            'recommendation' => $this->infrastructureRecommendation($dominantStage, $summary),
        ];
    }

    protected function imageTimingPayload(IntakeBatchImage $image, $snapshotTime): array
    {
        $createdAt = $image->created_at;
        $startedAt = $image->started_at;
        $completedAt = $image->completed_at;

        return [
            'queue_wait_seconds' => $this->secondsBetween($createdAt, $startedAt ?: $snapshotTime),
            'processing_seconds' => $startedAt ? $this->secondsBetween($startedAt, $completedAt ?: $snapshotTime) : null,
            'total_elapsed_seconds' => $this->secondsBetween($createdAt, $completedAt ?: $snapshotTime),
        ];
    }

    protected function secondsBetween($start, $end): ?int
    {
        if (! $start || ! $end) {
            return null;
        }

        return max(0, $start->diffInSeconds($end));
    }

    protected function pushMetric(array &$target, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $normalized = (int) round((float) $value);

        if ($normalized < 0) {
            return;
        }

        $target[] = $normalized;
    }

    protected function averageMetric(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        return (int) round(array_sum($values) / count($values));
    }

    protected function dominantInfrastructureStage(array $summary): ?string
    {
        $candidates = [
            'queue_wait' => $summary['avg_queue_wait_seconds'] ?? null,
            'ai_slot_wait' => $summary['avg_ai_slot_wait_seconds'] ?? null,
            'ai_processing' => $summary['avg_ai_processing_seconds'] ?? null,
            'aggregation' => $summary['avg_aggregation_seconds'] ?? null,
        ];

        $dominantStage = null;
        $dominantSeconds = -1;

        foreach ($candidates as $stage => $seconds) {
            if ($seconds === null || $seconds <= $dominantSeconds) {
                continue;
            }

            $dominantStage = $stage;
            $dominantSeconds = $seconds;
        }

        return $dominantStage;
    }

    protected function infrastructureRecommendation(?string $dominantStage, array $summary): string
    {
        $serialBatchProcessing = (bool) ($summary['serial_batch_processing'] ?? false);
        $totalImages = (int) ($summary['total_images'] ?? 0);
        $distinctWorkers = (int) ($summary['distinct_workers'] ?? 0);

        return match ($dominantStage) {
            'queue_wait' => $serialBatchProcessing
                ? sprintf('Queue wait is mainly intra-batch waiting: %d image%s ran through %d worker%s, so later images sat behind earlier ones. Add more intake workers if you want multi-image batches to parallelize, or keep one worker if strict serialization is intentional.', $totalImages, $totalImages === 1 ? '' : 's', max(1, $distinctWorkers), max(1, $distinctWorkers) === 1 ? '' : 's')
                : 'Queue pickup is the largest delay. Add more intake workers, reduce worker sleep, or isolate intake from other queue workloads.',
            'ai_slot_wait' => 'Gemini slot contention is the largest delay. Raise intake AI concurrency carefully, add provider capacity, or shift more work to OCR before AI.',
            'ai_processing' => 'Upstream AI processing is the largest delay. Reduce image size, trim prompt cost, or move easy rows to OCR-first extraction.',
            'aggregation' => 'Batch aggregation is the largest delay. Optimize row normalization and database writes, or defer heavy aggregation work off the hot path.',
            default => 'No dominant bottleneck is clear yet. Capture a few more batches to compare queue wait, AI slot wait, and AI processing time.',
        };
    }

    protected function claimImage(int $imageId, string $claimToken, string $claimedBy, $claimedAt): bool
    {
        return IntakeBatchImage::query()
            ->whereKey($imageId)
            ->whereIn('status', [
                IntakeBatchImageStatus::QUEUED->value,
                IntakeBatchImageStatus::RETRY_PENDING->value,
            ])
            ->update([
                'status' => IntakeBatchImageStatus::PROCESSING->value,
                'claim_token' => $claimToken,
                'claimed_at' => $claimedAt,
                'claimed_by' => $claimedBy,
                'completed_at' => null,
                'updated_at' => $claimedAt,
            ]) === 1;
    }

    protected function beginProcessingAttempt(int $imageId, string $claimToken): bool
    {
        return IntakeBatchImage::query()
            ->whereKey($imageId)
            ->where('status', IntakeBatchImageStatus::PROCESSING->value)
            ->where('claim_token', $claimToken)
            ->update([
                'started_at' => DB::raw('COALESCE(started_at, CURRENT_TIMESTAMP)'),
                'completed_at' => null,
                'last_error' => null,
                'attempts_count' => DB::raw('attempts_count + 1'),
                'updated_at' => now(),
            ]) === 1;
    }

    protected function releaseClaimForSlotWait(int $imageId, string $claimToken): bool
    {
        return IntakeBatchImage::query()
            ->whereKey($imageId)
            ->where('status', IntakeBatchImageStatus::PROCESSING->value)
            ->where('claim_token', $claimToken)
            ->update([
                'status' => IntakeBatchImageStatus::QUEUED->value,
                'claim_token' => null,
                'claimed_at' => null,
                'claimed_by' => null,
                'completed_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    protected function finalizeImage(int $imageId, string $claimToken, array $attributes): bool
    {
        if (($attributes['status'] ?? null) instanceof IntakeBatchImageStatus) {
            $attributes['status'] = $attributes['status']->value;
        }

        if (array_key_exists('metadata', $attributes) && is_array($attributes['metadata'])) {
            $attributes['metadata'] = json_encode($attributes['metadata'], JSON_THROW_ON_ERROR);
        }

        return IntakeBatchImage::query()
            ->whereKey($imageId)
            ->where('status', IntakeBatchImageStatus::PROCESSING->value)
            ->where('claim_token', $claimToken)
            ->update([
                ...$attributes,
                'updated_at' => now(),
            ]) === 1;
    }

    protected function workerIdentity(): string
    {
        $host = gethostname() ?: php_uname('n') ?: 'unknown-host';
        $pid = getmypid();

        return $pid ? sprintf('%s:%s', $host, $pid) : $host;
    }

    protected function dispatchAiStage(int $imageId, int $delaySeconds = 0): void
    {
        $dispatch = ProcessIntakeBatchImageAiJob::dispatch($imageId);

        if ($delaySeconds > 0) {
            $dispatch->delay(now()->addSeconds($delaySeconds));
        }
    }

    protected function dispatchAggregationStage(int $batchId, ?int $imageId = null): void
    {
        AggregateIntakeBatchJob::dispatch($batchId, $imageId);
    }

    protected function decodeImageMetadata(?string $payload): array
    {
        if (blank($payload)) {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function intakeRetryDecision(\Throwable $exception, int $attemptNumber): array
    {
        $statusCode = $exception instanceof RequestException ? $exception->response?->status() : null;
        $message = Str::lower($exception->getMessage());
        $maxAttempts = $this->maxIntakeAttempts();

        if ($exception instanceof RequestException && $statusCode === 404) {
            return [
                'should_retry' => false,
                'delay_seconds' => 0,
            ];
        }

        if ($exception instanceof RuntimeException && Str::contains($message, ['invalid json payload', 'image-only', 'api key is not configured'])) {
            return [
                'should_retry' => false,
                'delay_seconds' => 0,
            ];
        }

        if ($attemptNumber >= $maxAttempts) {
            return [
                'should_retry' => false,
                'delay_seconds' => 0,
            ];
        }

        if ($exception instanceof RequestException && $statusCode === 429) {
            return [
                'should_retry' => true,
                'delay_seconds' => $this->retryDelayForStatusCode(429, $attemptNumber),
            ];
        }

        if ($exception instanceof ConnectionException) {
            return [
                'should_retry' => true,
                'delay_seconds' => $this->retryDelayForStatusCode(503, $attemptNumber),
            ];
        }

        if ($exception instanceof RequestException && in_array($statusCode, [408, 500, 502, 503, 504], true)) {
            return [
                'should_retry' => true,
                'delay_seconds' => $this->retryDelayForStatusCode($statusCode, $attemptNumber),
            ];
        }

        if (Str::contains($message, [
            'high demand',
            'overload',
            'temporarily overloaded',
            'rate-limited',
            'status code 429',
            'status code 503',
            'connection timed out',
        ])) {
            return [
                'should_retry' => true,
                'delay_seconds' => $this->retryDelayForStatusCode(503, $attemptNumber),
            ];
        }

        return [
            'should_retry' => false,
            'delay_seconds' => 0,
        ];
    }

    protected function maxIntakeAttempts(): int
    {
        return max(1, (int) config('services.gemini.intake_max_attempts', 2));
    }

    protected function retryDelayForStatusCode(?int $statusCode, int $attemptNumber): int
    {
        return match ($statusCode) {
            429 => $attemptNumber === 1 ? random_int(12, 20) : random_int(30, 60),
            408, 500, 502, 503, 504 => $attemptNumber === 1 ? random_int(8, 15) : random_int(20, 40),
            default => $attemptNumber === 1 ? random_int(8, 15) : random_int(20, 40),
        };
    }
}