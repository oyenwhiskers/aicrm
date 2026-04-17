<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class IntakeImagePreprocessService
{
    public function buildMetadata(UploadedFile $image, array $clientMetadata = []): array
    {
        $original = $this->normalizeImageShape(data_get($clientMetadata, 'original', []));
        $optimized = $this->normalizeImageShape(data_get($clientMetadata, 'optimized', []));
        $receivedAt = now()->toIso8601String();
        $strategy = data_get($clientMetadata, 'strategy', 'server_received');

        return [
            'strategy' => $strategy,
            'prepared_at' => data_get($clientMetadata, 'prepared_at'),
            'received_at' => $receivedAt,
            'resized' => (bool) data_get($clientMetadata, 'resized', false),
            'compressed' => (bool) data_get($clientMetadata, 'compressed', false),
            'scale' => $this->normalizeScale(data_get($clientMetadata, 'scale')),
            'original' => array_replace([
                'name' => $image->getClientOriginalName(),
                'type' => $image->getMimeType() ?: 'application/octet-stream',
                'size' => $image->getSize(),
                'width' => null,
                'height' => null,
            ], $original),
            'optimized' => array_replace([
                'name' => $image->getClientOriginalName(),
                'type' => $image->getMimeType() ?: 'application/octet-stream',
                'size' => $image->getSize(),
                'width' => null,
                'height' => null,
            ], $optimized),
            'server_received' => [
                'name' => $image->getClientOriginalName(),
                'type' => $image->getMimeType() ?: 'application/octet-stream',
                'size' => $image->getSize(),
            ],
            'transfer_saved_bytes' => $this->savedBytes($original, $optimized),
        ];
    }

    protected function normalizeImageShape(array $shape): array
    {
        return [
            'name' => data_get($shape, 'name'),
            'type' => data_get($shape, 'type'),
            'size' => $this->normalizeInteger(data_get($shape, 'size')),
            'width' => $this->normalizeInteger(data_get($shape, 'width')),
            'height' => $this->normalizeInteger(data_get($shape, 'height')),
        ];
    }

    protected function normalizeInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    protected function normalizeScale(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (float) $value);
    }

    protected function savedBytes(array $original, array $optimized): ?int
    {
        $originalSize = $original['size'] ?? null;
        $optimizedSize = $optimized['size'] ?? null;

        if ($originalSize === null || $optimizedSize === null) {
            return null;
        }

        return $originalSize - $optimizedSize;
    }
}