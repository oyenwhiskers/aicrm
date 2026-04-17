<?php

namespace App\Services;

use App\Models\IntakeBatchImage;

class IntakeImageStageService
{
    public function initialMetadata(array $metadata = []): array
    {
        $now = now()->toIso8601String();
        $stages = [
            'queued' => [
                'state' => 'waiting',
                'entered_at' => $now,
                'updated_at' => $now,
            ],
        ];

        if (! empty($metadata['preprocess'])) {
            $stages['preprocess'] = [
                'state' => 'completed',
                'entered_at' => $now,
                'updated_at' => $now,
                'completed_at' => $now,
                'strategy' => data_get($metadata, 'preprocess.strategy'),
            ];
        }

        $metadata['pipeline'] = [
            'current_stage' => 'queued',
            'current_state' => 'waiting',
            'last_transition_at' => $now,
            'stages' => $stages,
        ];

        return $metadata;
    }

    public function markStage(IntakeBatchImage $image, string $stage, string $state = 'active', array $context = []): IntakeBatchImage
    {
        $metadata = $image->metadata ?? [];
        $pipeline = $metadata['pipeline'] ?? [
            'current_stage' => 'queued',
            'current_state' => 'waiting',
            'last_transition_at' => now()->toIso8601String(),
            'stages' => [],
        ];

        $now = now()->toIso8601String();
        $previousStage = $pipeline['current_stage'] ?? null;
        $previousState = $pipeline['current_state'] ?? null;

        if ($previousStage && $previousStage !== $stage && isset($pipeline['stages'][$previousStage])) {
            $previousStageEntry = $pipeline['stages'][$previousStage];

            if (! isset($previousStageEntry['started_at']) && in_array($previousState, ['active', 'waiting', 'queued'], true)) {
                $previousStageEntry['started_at'] = $previousStageEntry['entered_at'] ?? $now;
            }

            if (! isset($previousStageEntry['completed_at'])) {
                $previousStageEntry['completed_at'] = $now;
            }

            if (in_array($previousState, ['active', 'waiting', 'queued'], true)) {
                $previousStageEntry['state'] = 'completed';
            }

            $previousStageEntry['updated_at'] = $now;
            $pipeline['stages'][$previousStage] = $previousStageEntry;
        }

        $stageEntry = $pipeline['stages'][$stage] ?? [];

        $stageEntry = array_merge($stageEntry, $context, [
            'state' => $state,
            'updated_at' => $now,
        ]);

        if (! isset($stageEntry['entered_at'])) {
            $stageEntry['entered_at'] = $now;
        }

        if ($state === 'active' && ! isset($stageEntry['started_at'])) {
            $stageEntry['started_at'] = $now;
        }

        if (in_array($state, ['completed', 'failed', 'retry_pending'], true)) {
            $stageEntry['completed_at'] = $now;
        }

        $pipeline['current_stage'] = $stage;
        $pipeline['current_state'] = $state;
        $pipeline['last_transition_at'] = $now;
        $pipeline['stages'][$stage] = $stageEntry;

        $metadata['pipeline'] = $pipeline;

        $image->forceFill([
            'metadata' => $metadata,
        ])->save();

        return $image->fresh();
    }

    public function mergedMetadata(IntakeBatchImage $image, array $updates = []): array
    {
        return array_replace_recursive($image->metadata ?? [], $updates);
    }

    public function stagePayload(IntakeBatchImage $image): array
    {
        $pipeline = data_get($image->metadata, 'pipeline', [
            'current_stage' => 'queued',
            'current_state' => 'waiting',
            'last_transition_at' => $image->created_at?->toIso8601String(),
            'stages' => [],
        ]);

        $currentStage = data_get($pipeline, 'current_stage');

        $pipeline['stages'] = collect($pipeline['stages'] ?? [])
            ->map(fn (array $stage, string $stageName) => array_merge($stage, [
                'elapsed_seconds' => $this->elapsedSeconds(
                    data_get($stage, 'started_at') ?: data_get($stage, 'entered_at'),
                    data_get($stage, 'completed_at'),
                    $currentStage === $stageName,
                ),
            ]))
            ->all();

        return $pipeline;
    }

    protected function elapsedSeconds(?string $startedAt, ?string $completedAt, bool $isCurrentStage = false): ?int
    {
        if (! $startedAt) {
            return null;
        }

        $start = strtotime($startedAt);

        if ($start === false) {
            return null;
        }

        if (! $completedAt && ! $isCurrentStage) {
            return null;
        }

        $end = $completedAt ? strtotime($completedAt) : time();

        if ($end === false) {
            $end = time();
        }

        return max(0, $end - $start);
    }
}