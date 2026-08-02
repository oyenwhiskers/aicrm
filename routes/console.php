<?php

use App\Models\AiUsageLog;
use App\Services\AiUsageLogService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai-monitoring:backfill-costs {--dry-run : Preview affected rows without saving}', function (AiUsageLogService $aiUsageLogService) {
    $query = AiUsageLog::query()
        ->whereNull('estimated_cost')
        ->where('provider', 'gemini')
        ->whereNotNull('model')
        ->where(function ($query) {
            $query->whereNotNull('input_tokens')
                ->orWhereNotNull('output_tokens');
        })
        ->orderBy('id');

    $totalCandidates = (clone $query)->count();

    if ($totalCandidates === 0) {
        $this->info('No AI usage rows require cost backfill.');

        return self::SUCCESS;
    }

    $this->info("Found {$totalCandidates} AI usage rows with missing estimated cost.");

    $updatedCount = 0;
    $skippedCount = 0;
    $dryRun = (bool) $this->option('dry-run');

    $query->chunkById(200, function ($logs) use ($aiUsageLogService, &$updatedCount, &$skippedCount, $dryRun) {
        foreach ($logs as $log) {
            $estimatedCost = $aiUsageLogService->estimateCost(
                (string) $log->provider,
                (string) $log->model,
                $log->input_tokens,
                $log->output_tokens,
            );

            if ($estimatedCost === null) {
                $skippedCount++;
                continue;
            }

            if (! $dryRun) {
                $log->forceFill([
                    'estimated_cost' => $estimatedCost,
                ])->save();
            }

            $updatedCount++;
        }
    });

    $modeLabel = $dryRun ? 'Dry run complete.' : 'Backfill complete.';
    $this->info($modeLabel);
    $this->line("Updated rows: {$updatedCount}");
    $this->line("Skipped rows: {$skippedCount}");

    return self::SUCCESS;
})->purpose('Backfill missing estimated Gemini cost values for AI usage logs');
