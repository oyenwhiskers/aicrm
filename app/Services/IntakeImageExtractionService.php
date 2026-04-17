<?php

namespace App\Services;

use App\Models\IntakeBatch;
use App\Models\IntakeBatchImage;

class IntakeImageExtractionService
{
    public function __construct(
        protected LeadCaptureService $leadCaptureService,
    ) {
    }

    public function extractAndStore(IntakeBatchImage $image, IntakeBatch $batch): array
    {
        $result = $this->leadCaptureService->extractFromStoredImage(
            $image->storage_disk,
            $image->storage_path,
            data_get($image->metadata, 'mime_type'),
            $batch->source,
        );

        $this->replaceExtractedRows($image, $batch, $result);

        return [
            'result' => $result,
            'row_count' => count($result['rows'] ?? []),
            'metadata' => $this->buildImageMetadata($image, $result),
        ];
    }

    protected function replaceExtractedRows(IntakeBatchImage $image, IntakeBatch $batch, array $result): void
    {
        $image->extractedRows()->delete();

        $rows = collect($result['rows'] ?? [])
            ->map(fn (array $row) => [
                'intake_batch_id' => $batch->id,
                'name' => $row['name'],
                'phone_number' => $row['phone_number'],
                'source' => $row['source'] ?? $batch->source,
                'raw_name' => $row['raw_name'] ?? null,
                'raw_phone_number' => $row['raw_phone_number'] ?? null,
                'confidence' => $row['confidence'] ?? 'medium',
                'notes' => $row['notes'] ?? null,
                'metadata' => [
                    'source_summary' => $result['summary'] ?? null,
                ],
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if ($rows !== []) {
            $image->extractedRows()->createMany($rows);
        }
    }

    protected function buildImageMetadata(IntakeBatchImage $image, array $result): array
    {
        $metadata = $image->metadata ?? [];
        $metadata['summary'] = $result['summary'] ?? null;
        $metadata['needs_review'] = (bool) ($result['needs_review'] ?? false);

        return $metadata;
    }
}