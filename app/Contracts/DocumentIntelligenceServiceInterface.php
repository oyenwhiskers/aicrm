<?php

namespace App\Contracts;

interface DocumentIntelligenceServiceInterface
{
    public function isConfigured(): bool;

    public function requiresSharedGeminiSlot(): bool;

    public function extractDocument(array $documentPayload): array;
}
