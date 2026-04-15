<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\Lead;

class LeadCompletenessService
{
    public function summarize(Lead $lead): array
    {
        $lead->loadMissing('documents');

        $requirements = collect(DocumentType::requiredChecklistItemsForPrototype());
        $documents = $lead->documents
            ->sortByDesc(fn ($document) => optional($document->uploaded_at)->timestamp ?? $document->id)
            ->values();

        $assignedDocuments = $this->assignChecklistDocuments($documents);

        $items = $requirements->map(function (array $requirement) use ($assignedDocuments) {
            $document = $assignedDocuments[$requirement['key']] ?? null;
            $reviewNeeded = $document ? $this->documentNeedsReview($document) : false;

            return [
                'key' => $requirement['key'],
                'label' => $requirement['label'],
                'document_type' => $requirement['document_type'],
                'is_complete' => $document !== null && ! $reviewNeeded,
                'is_missing' => $document === null,
                'needs_review' => $document !== null && $reviewNeeded,
                'document' => $document ? $this->transformDocument($document) : null,
                'detail' => $this->checklistItemDetail($requirement['key'], $document),
            ];
        })->values();

        $groupedItems = $items
            ->groupBy('document_type')
            ->map(function ($group, string $documentType) {
                $receivedCount = $group->where('document', '!==', null)->count();
                $completeCount = $group->where('is_complete', true)->count();
                $reviewCount = $group->where('needs_review', true)->count();

                return [
                    'document_type' => $documentType,
                    'label' => $this->groupLabel($documentType),
                    'required_count' => $group->count(),
                    'received_count' => $receivedCount,
                    'missing_count' => $group->where('is_missing', true)->count(),
                    'review_count' => $reviewCount,
                    'is_complete' => $completeCount === $group->count(),
                    'slots' => $group->values()->all(),
                ];
            })
            ->values();

        $isComplete = $items->every(fn (array $item) => $item['is_complete']);
        $hasReviewItems = $items->contains(fn (array $item) => $item['needs_review']);

        return [
            'items' => $groupedItems->all(),
            'required_document_type_count' => $groupedItems->count(),
            'received_required_document_count' => $groupedItems->where('received_count', '>', 0)->count(),
            'required_document_slot_count' => $items->count(),
            'received_required_slot_count' => $items->where('document', '!==', null)->count(),
            'completed_required_group_count' => $groupedItems->where('is_complete', true)->count(),
            'needs_review_count' => $items->where('needs_review', true)->count(),
            'is_complete' => $isComplete && ! $hasReviewItems,
            'is_partial' => $items->where('document', '!==', null)->count() > 0 && ! ($isComplete && ! $hasReviewItems),
            'has_review_items' => $hasReviewItems,
        ];
    }

    public function documentAssignmentKeys(Lead $lead): array
    {
        $lead->loadMissing('documents');

        $documents = $lead->documents
            ->sortByDesc(fn ($document) => optional($document->uploaded_at)->timestamp ?? $document->id)
            ->values();

        $assignedDocuments = $this->assignChecklistDocuments($documents);
        $assignmentKeys = [];

        foreach ($assignedDocuments as $key => $document) {
            $assignmentKeys[$document->id] = $key;
        }

        return $assignmentKeys;
    }

    protected function assignChecklistDocuments($documents): array
    {
        $assigned = [];
        $taken = [];

        foreach ($documents as $document) {
            $manualKey = data_get($document->metadata, 'manual_assignment_key');

            if ($manualKey && ! in_array($manualKey, $taken, true)) {
                $assigned[$manualKey] = $document;
                $taken[] = $manualKey;
            }
        }

        foreach ($documents as $document) {
            $legacyKey = match (data_get($document->metadata, 'document_slot')) {
                'payslip_first_month' => 'payslip_1',
                'payslip_second_month' => 'payslip_2',
                'payslip_third_month' => 'payslip_3',
                default => data_get($document->metadata, 'document_slot'),
            };

            if ($legacyKey && ! in_array($legacyKey, $taken, true)) {
                $assigned[$legacyKey] = $document;
                $taken[] = $legacyKey;
            }
        }

        foreach ($documents as $document) {
            $documentType = data_get($document->metadata, 'effective_document_type') ?? $document->document_type->value;
            $side = data_get($document->metadata, 'classification.ic_side');

            if ($documentType === DocumentType::IC->value && ! $this->documentNeedsReview($document)) {
                $key = $side === 'back' ? 'ic_back' : ($side === 'front' ? 'ic_front' : null);

                if ($key && ! isset($assigned[$key])) {
                    $assigned[$key] = $document;
                    $taken[] = $key;
                }
            }
        }

        $remainingIcKeys = collect(['ic_front', 'ic_back'])
            ->reject(fn (string $key) => isset($assigned[$key]))
            ->values();

        $remainingIcDocuments = $documents
            ->filter(function ($document) use ($taken, $assigned) {
                $documentType = data_get($document->metadata, 'effective_document_type') ?? $document->document_type->value;

                return $documentType === DocumentType::IC->value
                    && ! $this->documentNeedsReview($document)
                    && ! in_array(data_get($document->metadata, 'manual_assignment_key'), $taken, true)
                    && ! in_array($document->id, collect($assigned)->map->id->all(), true);
            })
            ->values();

        if ($remainingIcKeys->count() === 1 && $remainingIcDocuments->count() === 1) {
            $key = $remainingIcKeys->first();
            $document = $remainingIcDocuments->first();
            $assigned[$key] = $document;
            $taken[] = $key;
        }

        foreach (['ramci', 'ctos'] as $singleKey) {
            if (isset($assigned[$singleKey])) {
                continue;
            }

            $document = $documents->first(function ($document) use ($singleKey, $taken) {
                $documentType = data_get($document->metadata, 'effective_document_type') ?? $document->document_type->value;

                return $documentType === $singleKey
                    && ! $this->documentNeedsReview($document)
                    && ! in_array(data_get($document->metadata, 'manual_assignment_key'), $taken, true);
            });

            if ($document) {
                $assigned[$singleKey] = $document;
                $taken[] = $singleKey;
            }
        }

        $payslips = $documents
            ->filter(function ($document) {
                $documentType = data_get($document->metadata, 'effective_document_type') ?? $document->document_type->value;
                return $documentType === DocumentType::PAYSLIP->value
                    && ! $this->documentNeedsReview($document)
                    && filled(data_get($document->metadata, 'classification.statement_period'));
            })
            ->unique(fn ($document) => data_get($document->metadata, 'classification.statement_period'))
            ->sortBy(fn ($document) => data_get($document->metadata, 'classification.statement_period'))
            ->values();

        $streak = $this->latestConsecutivePayslipStreak($payslips);
        foreach ($streak as $index => $document) {
            $key = 'payslip_' . ($index + 1);
            if (! isset($assigned[$key])) {
                $assigned[$key] = $document;
                $taken[] = $key;
            }
        }

        $epfDocuments = $documents
            ->filter(function ($document) {
                $documentType = data_get($document->metadata, 'effective_document_type') ?? $document->document_type->value;
                return $documentType === DocumentType::EPF->value
                    && ! $this->documentNeedsReview($document)
                    && filled(data_get($document->metadata, 'classification.statement_year'));
            })
            ->groupBy(fn ($document) => (string) data_get($document->metadata, 'classification.statement_year'))
            ->map(fn ($group) => $group->sortByDesc(fn ($document) => optional($document->uploaded_at)->timestamp ?? $document->id)->first())
            ->sortKeys()
            ->values();

        foreach ($epfDocuments->take(2)->values() as $index => $document) {
            $key = 'epf_year_' . ($index + 1);
            if (! isset($assigned[$key])) {
                $assigned[$key] = $document;
                $taken[] = $key;
            }
        }

        return $assigned;
    }

    protected function latestConsecutivePayslipStreak($documents): array
    {
        $best = [];
        $current = [];
        $previousDate = null;

        foreach ($documents as $document) {
            $period = data_get($document->metadata, 'classification.statement_period');
            if (! $period) {
                continue;
            }

            $currentDate = \Carbon\Carbon::createFromFormat('Y-m', $period)->startOfMonth();

            if ($previousDate && $previousDate->copy()->addMonth()->equalTo($currentDate)) {
                $current[] = $document;
            } else {
                $current = [$document];
            }

            if (count($current) >= 3) {
                $best = array_slice($current, -3, 3);
            }

            $previousDate = $currentDate;
        }

        return $best;
    }

    protected function documentNeedsReview($document): bool
    {
        if (data_get($document->metadata, 'manual_review_resolved')) {
            return false;
        }

        return (bool) data_get($document->metadata, 'classification.needs_review', false);
    }

    protected function transformDocument($document): array
    {
        return [
            'id' => $document->id,
            'original_filename' => $document->original_filename,
            'uploaded_at' => $document->uploaded_at?->toIso8601String(),
            'storage_path' => $document->storage_path,
            'document_type' => data_get($document->metadata, 'effective_document_type') ?? $document->document_type->value,
        ];
    }

    protected function checklistItemDetail(string $key, $document): ?string
    {
        if (! $document) {
            return null;
        }

        return match (true) {
            str_starts_with($key, 'payslip_') => data_get($document->metadata, 'classification.statement_period'),
            str_starts_with($key, 'epf_year_') => (string) data_get($document->metadata, 'classification.statement_year'),
            str_starts_with($key, 'ic_') => ucfirst((string) data_get($document->metadata, 'classification.ic_side', '')),
            default => null,
        };
    }

    protected function groupLabel(string $documentType): string
    {
        return match ($documentType) {
            DocumentType::IC->value => 'Upload IC',
            DocumentType::PAYSLIP->value => 'Upload Payslip',
            DocumentType::EPF->value => 'Upload EPF',
            DocumentType::RAMCI->value => 'Upload RAMCI',
            DocumentType::CTOS->value => 'Upload CTOS',
            default => strtoupper($documentType),
        };
    }
}