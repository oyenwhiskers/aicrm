<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\ExtractionStatus;
use App\Models\LeadDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class ExtractionService
{
    public function __construct(
        protected ActivityLogService $activityLogService,
        protected GeminiExtractionService $geminiExtractionService,
    ) {
    }

    public function extract(LeadDocument $document)
    {
        $document->loadMissing('lead.profile');

        if (blank(config('services.openai.api_key'))) {
            return $this->storeUnavailableResult($document);
        }

        try {
            $payload = Storage::disk($document->storage_disk)->get($document->storage_path);

            $result = $this->geminiExtractionService->extract(
                $document->metadata['mime_type'] ?? null,
                base64_encode($payload)
            );

            $normalizedClassification = $this->normalizeClassification(
                $result['classification'] ?? [],
                $result['fields'] ?? [],
                $result['raw_text'] ?? null,
                $result['summary'] ?? null,
            );
            $detectedType = DocumentType::tryFrom((string) ($normalizedClassification['document_type'] ?? '')) ?? DocumentType::OTHER;
            $metadata = [
                ...($document->metadata ?? []),
                'classification' => [
                    'document_type' => $detectedType->value,
                    'ic_side' => $normalizedClassification['ic_side'] ?? null,
                    'statement_year' => $normalizedClassification['statement_year'] ?? null,
                    'statement_month' => $normalizedClassification['statement_month'] ?? null,
                    'statement_period' => $normalizedClassification['statement_period'] ?? null,
                    'confidence' => $result['confidence'] ?? 'medium',
                    'needs_review' => (bool) ($result['needs_review'] ?? false),
                ],
                'effective_document_type' => data_get($document->metadata, 'manual_assignment_key')
                    ? (data_get($document->metadata, 'effective_document_type') ?? $detectedType->value)
                    : $detectedType->value,
            ];

            $document->forceFill([
                'document_type' => $detectedType,
                'metadata' => $metadata,
            ])->save();

            $record = $document->lead->extractedData()->updateOrCreate(
                ['lead_document_id' => $document->id],
                [
                    'document_type' => $detectedType,
                    'extracted_summary' => $result['summary'] ?? 'Extraction completed.',
                    'structured_fields' => $result,
                    'extraction_status' => ($result['needs_review'] ?? false)
                        ? ExtractionStatus::REVIEW_REQUIRED
                        : ExtractionStatus::COMPLETED,
                    'extracted_at' => now(),
                ]
            );

            $this->syncLeadData($document, $result['fields'] ?? []);
            $this->activityLogService->log(
                $document->lead,
                'document.extracted',
                'Document extraction completed.',
                [
                    'document_id' => $document->id,
                    'document_type' => $document->document_type->value,
                    'extraction_status' => $record->extraction_status->value,
                ]
            );

            return $record;
        } catch (\Throwable $exception) {
            $metadata = [
                ...($document->metadata ?? []),
                'classification' => [
                    'document_type' => $document->document_type->value,
                    'confidence' => 'low',
                    'needs_review' => true,
                ],
            ];

            $document->forceFill(['metadata' => $metadata])->save();

            $record = $document->lead->extractedData()->updateOrCreate(
                ['lead_document_id' => $document->id],
                [
                    'document_type' => $document->document_type,
                    'extracted_summary' => 'Extraction failed and requires manual review.',
                    'structured_fields' => [
                        'error_message' => $exception->getMessage(),
                    ],
                    'extraction_status' => ExtractionStatus::FAILED,
                    'extracted_at' => now(),
                ]
            );

            $this->activityLogService->log(
                $document->lead,
                'document.extraction_failed',
                'Document extraction failed.',
                [
                    'document_id' => $document->id,
                    'document_type' => $document->document_type->value,
                    'error' => $exception->getMessage(),
                ]
            );

            return $record;
        }
    }

    protected function normalizeClassification(array $classification, array $fields, ?string $rawText = null, ?string $summary = null): array
    {
        $documentType = DocumentType::tryFrom((string) ($classification['document_type'] ?? '')) ?? DocumentType::OTHER;

        if ($documentType !== DocumentType::IC) {
            return $classification;
        }

        $side = $classification['ic_side'] ?? null;
        $hasFullName = filled($fields['full_name'] ?? null);
        $hasDob = filled($fields['date_of_birth'] ?? null);
        $text = strtolower(trim(implode(' ', array_filter([$rawText, $summary]))));
        $hasAddress = filled($fields['address'] ?? null);
        $hasBackMarkers = str_contains($text, 'touch n go')
            || str_contains($text, 'touchngo')
            || str_contains($text, '80k chip')
            || str_contains($text, 'chip')
            || str_contains($text, 'ketua pengarah pendaftaran negara')
            || str_contains($text, 'pendaftaran negara');
        $looksLikeBack = ($hasBackMarkers && ! $hasFullName && ! $hasDob)
            || ($hasAddress && ! $hasFullName);

        if (! in_array($side, ['front', 'back'], true)) {
            if ($hasFullName) {
                $classification['ic_side'] = 'front';
            } elseif ($looksLikeBack) {
                $classification['ic_side'] = 'back';
            }

            return $classification;
        }

        if ($side === 'front' && ! $hasFullName && $looksLikeBack) {
            $classification['ic_side'] = 'back';
        }

        if ($side === 'back' && $hasFullName && ! $hasBackMarkers) {
            $classification['ic_side'] = 'front';
        }

        return $classification;
    }

    protected function storeUnavailableResult(LeadDocument $document)
    {
        $metadata = [
            ...($document->metadata ?? []),
            'classification' => [
                'document_type' => $document->document_type->value,
                'confidence' => 'low',
                'needs_review' => true,
            ],
        ];

        $document->forceFill(['metadata' => $metadata])->save();

        return $document->lead->extractedData()->updateOrCreate(
            ['lead_document_id' => $document->id],
            [
                'document_type' => $document->document_type,
                'extracted_summary' => 'AI service is not configured. Manual review is required until OPENAI_API_KEY is set.',
                'structured_fields' => [
                    'ai_configured' => false,
                ],
                'extraction_status' => ExtractionStatus::REVIEW_REQUIRED,
                'extracted_at' => now(),
            ]
        );
    }

    protected function syncLeadData(LeadDocument $document, array $fields): void
    {
        $lead = $document->lead;
        $profile = $lead->profile ?? $lead->profile()->create();

        if ($document->document_type === DocumentType::IC) {
            if (blank($lead->ic_number) && filled($fields['ic_number'] ?? null)) {
                $lead->ic_number = $fields['ic_number'];
            }

            if (blank($profile->age) && filled($fields['date_of_birth'] ?? null)) {
                try {
                    $profile->age = Carbon::parse($fields['date_of_birth'])->age;
                } catch (\Throwable) {
                    // Ignore invalid date strings from extraction and preserve manual correction later.
                }
            }

            $lead->save();
            $profile->save();

            return;
        }

        if ($document->document_type === DocumentType::PAYSLIP) {
            if (blank($profile->employer) && filled($fields['employer'] ?? null)) {
                $profile->employer = $fields['employer'];
            }

            if (blank($profile->salary)) {
                $salary = $fields['gross_income'] ?? $fields['basic_salary'] ?? null;

                if (is_numeric($salary)) {
                    $profile->salary = $salary;
                }
            }

            if (blank($profile->employment_type) && filled($fields['employment_type'] ?? null)) {
                $profile->employment_type = $fields['employment_type'];
            }

            $profile->save();
        }
    }
}