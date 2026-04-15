<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\Lead;

class LeadCompletenessService
{
    public function summarize(Lead $lead): array
    {
        $requirements = DocumentType::requiredForPrototype();
        $documentCounts = $lead->documents
            ->groupBy('document_type')
            ->map(fn ($documents) => $documents->count())
            ->all();

        $items = [];
        $receivedRequiredDocumentCount = 0;

        foreach ($requirements as $documentType => $requiredCount) {
            $receivedCount = $documentCounts[$documentType] ?? 0;
            $remainingCount = max($requiredCount - $receivedCount, 0);

            if ($receivedCount > 0) {
                $receivedRequiredDocumentCount++;
            }

            $items[] = [
                'document_type' => $documentType,
                'required_count' => $requiredCount,
                'received_count' => $receivedCount,
                'missing_count' => $remainingCount,
                'is_complete' => $remainingCount === 0,
            ];
        }

        $isComplete = collect($items)->every(fn (array $item) => $item['is_complete']);

        return [
            'items' => $items,
            'required_document_type_count' => count($requirements),
            'received_required_document_count' => $receivedRequiredDocumentCount,
            'is_complete' => $isComplete,
            'is_partial' => $receivedRequiredDocumentCount > 0 && ! $isComplete,
        ];
    }
}