<?php

namespace App\Services;

use App\Contracts\DocumentIntelligenceServiceInterface;
use App\Enums\DocumentType;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PythonDocumentIntelligenceService implements DocumentIntelligenceServiceInterface
{
    public function isConfigured(): bool
    {
        return filled(config('services.document_intelligence.python.base_url'));
    }

    public function requiresSharedGeminiSlot(): bool
    {
        return false;
    }

    public function extractDocument(array $documentPayload): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Python document intelligence service is not configured.');
        }

        $request = Http::timeout((int) config('services.document_intelligence.python.timeout', 60))
            ->withOptions([
                'verify' => (bool) config('services.document_intelligence.python.verify_ssl', true),
            ])
            ->when(
                filled(config('services.document_intelligence.python.token')),
                fn ($request) => $request->withToken((string) config('services.document_intelligence.python.token'))
            )
            ->acceptJson();

        $payload = [
            'document_id' => $documentPayload['document_id'] ?? null,
            'lead_id' => $documentPayload['lead_id'] ?? null,
            'filename' => $documentPayload['filename'] ?? $documentPayload['original_filename'] ?? null,
            'mime_type' => $documentPayload['mime_type'] ?? 'application/octet-stream',
            'source' => $documentPayload['source'] ?? null,
            'mode' => $documentPayload['mode'] ?? 'primary',
            'storage_disk' => $documentPayload['storage_disk'] ?? null,
            'storage_path' => $documentPayload['storage_path'] ?? null,
            'shared_storage_roots' => $documentPayload['shared_storage_roots'] ?? config('services.document_intelligence.shared_storage.disk_roots', []),
            'allowed_storage_disks' => $documentPayload['allowed_storage_disks'] ?? config('services.document_intelligence.shared_storage.enabled_disks', []),
        ];

        if (
            filled($payload['storage_disk'])
            && filled($payload['storage_path'])
        ) {
            $response = $request->asJson()->post($this->endpoint(), $payload);
        } else {
            $binaryPayload = $documentPayload['payload'] ?? null;

            if (is_string($binaryPayload)) {
                $request = $request->attach(
                    'file',
                    $binaryPayload,
                    $payload['filename'] ?? 'document.bin',
                    [
                        'Content-Type' => $payload['mime_type'],
                    ]
                );
            }

            $response = $request->post($this->endpoint(), [
                ...$payload,
                'content_base64' => is_string($binaryPayload) ? null : ($documentPayload['content_base64'] ?? null),
            ]);
        }

        if ($response->failed()) {
            $response->throw();
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new RuntimeException('Python document intelligence service returned an invalid JSON payload.');
        }

        return [
            'summary' => $decoded['summary'] ?? 'Extraction completed.',
            'confidence' => $decoded['confidence'] ?? 'medium',
            'needs_review' => (bool) ($decoded['needs_review'] ?? false),
            'review_reasons' => array_values(array_unique(array_filter(
                is_array($decoded['review_reasons'] ?? null) ? $decoded['review_reasons'] : []
            ))),
            'classification' => [
                'document_type' => $decoded['classification']['document_type'] ?? DocumentType::OTHER->value,
                'ic_side' => $decoded['classification']['ic_side'] ?? null,
                'statement_year' => $decoded['classification']['statement_year'] ?? null,
                'statement_month' => $decoded['classification']['statement_month'] ?? null,
                'statement_period' => $decoded['classification']['statement_period'] ?? null,
            ],
            'fields' => is_array($decoded['fields'] ?? null) ? $decoded['fields'] : [],
            'raw_text' => $decoded['raw_text'] ?? null,
            'provider_meta' => is_array($decoded['provider_meta'] ?? null) ? $decoded['provider_meta'] : [],
        ];
    }

    protected function endpoint(): string
    {
        $baseUrl = rtrim((string) config('services.document_intelligence.python.base_url'), '/');
        $path = '/'.ltrim((string) config('services.document_intelligence.python.extract_path', '/extract'), '/');

        return $baseUrl.$path;
    }
}
