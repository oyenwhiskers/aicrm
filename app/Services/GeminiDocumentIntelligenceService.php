<?php

namespace App\Services;

use App\Contracts\DocumentIntelligenceServiceInterface;

class GeminiDocumentIntelligenceService implements DocumentIntelligenceServiceInterface
{
    public function __construct(
        protected GeminiExtractionService $geminiExtractionService,
    ) {
    }

    public function isConfigured(): bool
    {
        return filled(config('services.gemini.api_key'));
    }

    public function requiresSharedGeminiSlot(): bool
    {
        return true;
    }

    public function extractDocument(array $documentPayload): array
    {
        $contentBase64 = $documentPayload['content_base64'] ?? null;

        if (! is_string($contentBase64) && isset($documentPayload['payload'])) {
            $contentBase64 = base64_encode((string) $documentPayload['payload']);
        }

        return $this->geminiExtractionService->extract(
            $documentPayload['mime_type'] ?? null,
            (string) $contentBase64,
        );
    }
}
