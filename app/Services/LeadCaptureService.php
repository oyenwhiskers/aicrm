<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class LeadCaptureService
{
    public function __construct(
        protected GeminiExtractionService $geminiExtractionService,
    ) {
    }

    public function extractFromImage(UploadedFile $image, ?string $source = null): array
    {
        return $this->extractFromBinary(
            $image->getMimeType(),
            $image->get(),
            $source,
            $image->getClientOriginalName(),
        );
    }

    public function extractFromStoredImage(string $disk, string $path, ?string $mimeType = null, ?string $source = null): array
    {
        return $this->extractFromBinary(
            $mimeType,
            Storage::disk($disk)->get($path),
            $source,
            basename($path),
        );
    }

    protected function extractFromBinary(?string $mimeType, string $binaryPayload, ?string $source = null, ?string $filename = null): array
    {
        $payload = base64_encode($binaryPayload);

        $result = $this->geminiExtractionService->extractLeadCaptureImage(
            $mimeType,
            $payload,
            [
                'request_context' => 'lead_intake',
                'input_mime_type' => $mimeType,
                'input_filename' => $filename,
            ],
        );

        $rows = collect($result['rows'] ?? [])
            ->map(function (array $row) use ($source) {
                return [
                    'name' => trim((string) ($row['name'] ?? '')),
                    'phone_number' => $this->normalizePhone($row['phone_number'] ?? ''),
                    'source' => $source ?: 'image extraction',
                    'raw_name' => $row['raw_name'] ?? null,
                    'raw_phone_number' => $row['raw_phone_number'] ?? null,
                    'confidence' => $row['confidence'] ?? 'medium',
                    'notes' => $row['notes'] ?? null,
                ];
            })
            ->filter(fn (array $row) => $row['name'] !== '' && $row['phone_number'] !== '')
            ->values()
            ->all();

        return [
            'summary' => $result['summary'] ?? 'Lead image extraction completed.',
            'needs_review' => (bool) ($result['needs_review'] ?? false),
            'rows' => $rows,
            'raw_text' => $result['raw_text'] ?? null,
        ];
    }

    protected function normalizePhone(mixed $value): string
    {
        $digits = preg_replace('/[^\d+]/', '', (string) $value) ?? '';

        if ($digits !== '' && ! str_starts_with($digits, '+')) {
            if (str_starts_with($digits, '60')) {
                return '+'.$digits;
            }

            if (str_starts_with($digits, '0')) {
                return '+6'.$digits;
            }
        }

        return $digits;
    }
}
