<?php

namespace App\Services;

use App\Models\LeadDocument;
use Illuminate\Support\Facades\Storage;

class DocumentPreprocessService
{
    public function prepare(LeadDocument $document): array
    {
        $mimeType = (string) ($document->metadata['mime_type'] ?? Storage::disk($document->storage_disk)->mimeType($document->storage_path) ?? 'application/octet-stream');
        $payload = Storage::disk($document->storage_disk)->get($document->storage_path);
        $sizeBytes = strlen($payload);
        $metadata = [
            'applied' => false,
            'skipped_reason' => null,
            'original' => [
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'width' => null,
                'height' => null,
            ],
            'optimized' => null,
        ];

        if (! $this->supportsOptimization($mimeType)) {
            $metadata['skipped_reason'] = 'unsupported_mime_type';

            return [
                'original' => [
                    'payload' => $payload,
                    'mime_type' => $mimeType,
                    'source' => 'original',
                ],
                'optimized' => null,
                'metadata' => $metadata,
            ];
        }

        $imageInfo = @getimagesizefromstring($payload);

        if (! is_array($imageInfo)) {
            $metadata['skipped_reason'] = 'unreadable_image';

            return [
                'original' => [
                    'payload' => $payload,
                    'mime_type' => $mimeType,
                    'source' => 'original',
                ],
                'optimized' => null,
                'metadata' => $metadata,
            ];
        }

        $metadata['original']['width'] = $imageInfo[0] ?? null;
        $metadata['original']['height'] = $imageInfo[1] ?? null;

        if (! $this->shouldOptimize($sizeBytes, $metadata['original']['width'], $metadata['original']['height'])) {
            $metadata['skipped_reason'] = 'within_threshold';

            return [
                'original' => [
                    'payload' => $payload,
                    'mime_type' => $mimeType,
                    'source' => 'original',
                ],
                'optimized' => null,
                'metadata' => $metadata,
            ];
        }

        $optimized = $this->optimizeImage($payload, $mimeType, $metadata['original']['width'], $metadata['original']['height']);

        if (! is_array($optimized)) {
            $metadata['skipped_reason'] = 'optimization_failed';

            return [
                'original' => [
                    'payload' => $payload,
                    'mime_type' => $mimeType,
                    'source' => 'original',
                ],
                'optimized' => null,
                'metadata' => $metadata,
            ];
        }

        $metadata['applied'] = true;
        $metadata['skipped_reason'] = null;
        $metadata['optimized'] = [
            'mime_type' => $optimized['mime_type'],
            'size_bytes' => strlen($optimized['payload']),
            'width' => $optimized['width'],
            'height' => $optimized['height'],
        ];

        return [
            'original' => [
                'payload' => $payload,
                'mime_type' => $mimeType,
                'source' => 'original',
            ],
            'optimized' => [
                'payload' => $optimized['payload'],
                'mime_type' => $optimized['mime_type'],
                'source' => 'optimized',
            ],
            'metadata' => $metadata,
        ];
    }

    protected function supportsOptimization(string $mimeType): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    protected function shouldOptimize(int $sizeBytes, ?int $width, ?int $height): bool
    {
        $maxDimension = max(1, (int) config('services.gemini.document_preprocess_max_dimension', 2200));
        $sizeThreshold = max(1, (int) config('services.gemini.document_preprocess_size_threshold_bytes', 2 * 1024 * 1024));

        return $sizeBytes > $sizeThreshold
            || ($width !== null && $width > $maxDimension)
            || ($height !== null && $height > $maxDimension);
    }

    protected function optimizeImage(string $payload, string $mimeType, int $width, int $height): ?array
    {
        $image = @imagecreatefromstring($payload);

        if (! $image) {
            return null;
        }

        try {
            $maxDimension = max(1, (int) config('services.gemini.document_preprocess_max_dimension', 2200));
            [$targetWidth, $targetHeight] = $this->targetDimensions($width, $height, $maxDimension);
            $workingImage = $image;

            if ($targetWidth !== $width || $targetHeight !== $height) {
                $resizedImage = imagecreatetruecolor($targetWidth, $targetHeight);

                if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
                    imagealphablending($resizedImage, false);
                    imagesavealpha($resizedImage, true);
                    $transparent = imagecolorallocatealpha($resizedImage, 0, 0, 0, 127);
                    imagefilledrectangle($resizedImage, 0, 0, $targetWidth, $targetHeight, $transparent);
                }

                imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
                $workingImage = $resizedImage;
            }

            ob_start();

            $encoded = match ($mimeType) {
                'image/png' => imagepng($workingImage, null, max(0, min(9, (int) config('services.gemini.document_preprocess_png_compression', 6)))),
                'image/webp' => imagewebp($workingImage, null, max(50, min(100, (int) config('services.gemini.document_preprocess_webp_quality', 82)))),
                default => imagejpeg($workingImage, null, max(50, min(100, (int) config('services.gemini.document_preprocess_jpeg_quality', 82)))),
            };

            $optimizedPayload = ob_get_clean();

            if ($workingImage !== $image) {
                imagedestroy($workingImage);
            }

            if (! $encoded || ! is_string($optimizedPayload) || $optimizedPayload === '') {
                return null;
            }

            return [
                'payload' => $optimizedPayload,
                'mime_type' => $mimeType,
                'width' => $targetWidth,
                'height' => $targetHeight,
            ];
        } finally {
            imagedestroy($image);
        }
    }

    protected function targetDimensions(int $width, int $height, int $maxDimension): array
    {
        if ($width <= $maxDimension && $height <= $maxDimension) {
            return [$width, $height];
        }

        $scale = min($maxDimension / max(1, $width), $maxDimension / max(1, $height));

        return [
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        ];
    }
}
