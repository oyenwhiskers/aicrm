<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\ExtractionStatus;
use App\Models\LeadDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ExtractionService
{
    public function __construct(
        protected ActivityLogService $activityLogService,
        protected DocumentPreprocessService $documentPreprocessService,
        protected GeminiExtractionService $geminiExtractionService,
    ) {
    }

    public function extract(LeadDocument $document)
    {
        $document->loadMissing('lead.profile');

        if (blank(config('services.gemini.api_key'))) {
            return $this->storeUnavailableResult($document);
        }

        try {
            $prepared = $this->documentPreprocessService->prepare($document);
            $metadata = $this->withPreprocessMetadata($document->metadata ?? [], $prepared['metadata']);
            data_set($metadata, 'extraction_pipeline.extraction_started_at', now()->toIso8601String());
            $document->forceFill(['metadata' => $metadata])->save();

            Log::info('document_preprocess_decision', [
                'event' => 'document_preprocess_decision',
                'document_id' => $document->id,
                'lead_id' => $document->lead_id,
                'applied' => (bool) data_get($prepared, 'metadata.applied', false),
                'skipped_reason' => data_get($prepared, 'metadata.skipped_reason'),
            ]);

            $attempts = [];
            $attempt = $this->runExtractionAttempt($prepared['optimized'] ?? $prepared['original']);
            $attempts[] = $this->attemptSummary($attempt);
            $finalAttempt = $attempt;
            $fallbackReason = null;

            if (($prepared['optimized'] ?? null) && ($reason = $this->fallbackReasonForAttempt($attempt))) {
                $fallbackReason = $reason;

                Log::info('document_fallback_to_original', [
                    'event' => 'document_fallback_to_original',
                    'document_id' => $document->id,
                    'lead_id' => $document->lead_id,
                    'reason' => $reason,
                ]);

                $finalAttempt = $this->runExtractionAttempt($prepared['original']);
                $attempts[] = $this->attemptSummary($finalAttempt);
            }

            $metadata = $this->buildSuccessfulMetadata(
                $document,
                $metadata,
                $finalAttempt,
                $attempts,
                $fallbackReason,
                $prepared['metadata'] ?? [],
            );

            $document->forceFill([
                'document_type' => $finalAttempt['detected_type'],
                'metadata' => $metadata,
            ])->save();

            $record = $document->lead->extractedData()->updateOrCreate(
                ['lead_document_id' => $document->id],
                [
                    'document_type' => $finalAttempt['detected_type'],
                    'extracted_summary' => $finalAttempt['result']['summary'] ?? 'Extraction completed.',
                    'structured_fields' => $finalAttempt['result'],
                    'extraction_status' => ($finalAttempt['result']['needs_review'] ?? false)
                        ? ExtractionStatus::REVIEW_REQUIRED
                        : ExtractionStatus::COMPLETED,
                    'extracted_at' => now(),
                ]
            );

            $this->syncLeadData($document, $finalAttempt['result']['fields'] ?? []);
            $this->activityLogService->log(
                $document->lead,
                'document.extracted',
                'Document extraction completed.',
                [
                    'document_id' => $document->id,
                    'document_type' => $document->document_type->value,
                    'extraction_status' => $record->extraction_status->value,
                    'extraction_input_source' => $finalAttempt['source'],
                    'fallback_triggered' => $fallbackReason !== null,
                ]
            );

            Log::info('document_extraction_success', [
                'event' => 'document_extraction_success',
                'document_id' => $document->id,
                'lead_id' => $document->lead_id,
                'input_source' => $finalAttempt['source'],
                'fallback_triggered' => $fallbackReason !== null,
                'extraction_status' => $record->extraction_status->value,
            ]);

            return $record;
        } catch (\Throwable $exception) {
            $metadata = $this->buildFailedMetadata($document->metadata ?? [], $exception->getMessage(), $document->document_type->value);
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

            Log::info('document_extraction_failed', [
                'event' => 'document_extraction_failed',
                'document_id' => $document->id,
                'lead_id' => $document->lead_id,
                'error' => $exception->getMessage(),
            ]);

            return $record;
        }
    }

    protected function runExtractionAttempt(array $source): array
    {
        $result = $this->geminiExtractionService->extract(
            $source['mime_type'] ?? null,
            base64_encode($source['payload'])
        );

        $normalizedClassification = $this->normalizeClassification(
            $result['classification'] ?? [],
            $result['fields'] ?? [],
            $result['raw_text'] ?? null,
            $result['summary'] ?? null,
        );

        return [
            'source' => $source['source'],
            'mime_type' => $source['mime_type'],
            'result' => $result,
            'classification' => $normalizedClassification,
            'detected_type' => DocumentType::tryFrom((string) ($normalizedClassification['document_type'] ?? '')) ?? DocumentType::OTHER,
        ];
    }

    protected function withPreprocessMetadata(array $metadata, array $preprocessMetadata): array
    {
        data_set($metadata, 'document_preprocess', $preprocessMetadata);

        return $metadata;
    }

    protected function buildSuccessfulMetadata(
        LeadDocument $document,
        array $metadata,
        array $finalAttempt,
        array $attempts,
        ?string $fallbackReason,
        array $preprocessMetadata,
    ): array {
        $detectedType = $finalAttempt['detected_type'];
        $classification = $finalAttempt['classification'];
        $result = $finalAttempt['result'];

        $metadata['classification'] = [
            'document_type' => $detectedType->value,
            'ic_side' => $classification['ic_side'] ?? null,
            'statement_year' => $classification['statement_year'] ?? null,
            'statement_month' => $classification['statement_month'] ?? null,
            'statement_period' => $classification['statement_period'] ?? null,
            'confidence' => $result['confidence'] ?? 'medium',
            'needs_review' => (bool) ($result['needs_review'] ?? false),
        ];
        $metadata['effective_document_type'] = data_get($document->metadata, 'manual_assignment_key')
            ? (data_get($document->metadata, 'effective_document_type') ?? $detectedType->value)
            : $detectedType->value;

        data_set($metadata, 'document_preprocess', $preprocessMetadata);
        data_set($metadata, 'extraction_pipeline.extraction_finished_at', now()->toIso8601String());
        data_set($metadata, 'extraction_pipeline.input_source', $finalAttempt['source']);
        data_set($metadata, 'extraction_pipeline.attempts', $attempts);
        data_set($metadata, 'extraction_pipeline.fallback.triggered', $fallbackReason !== null);
        data_set($metadata, 'extraction_pipeline.fallback.reason', $fallbackReason);
        data_set($metadata, 'extraction_pipeline.fallback.used_original', $finalAttempt['source'] === 'original' && count($attempts) > 1);

        return $metadata;
    }

    protected function buildFailedMetadata(array $metadata, string $errorMessage, string $defaultDocumentType): array
    {
        $metadata = [
            ...$metadata,
            'classification' => [
                'document_type' => data_get($metadata, 'classification.document_type', $defaultDocumentType),
                'confidence' => 'low',
                'needs_review' => true,
            ],
        ];
        data_set($metadata, 'extraction_pipeline.extraction_finished_at', now()->toIso8601String());
        data_set($metadata, 'extraction_pipeline.processing_error', $errorMessage);

        return $metadata;
    }

    protected function normalizeClassification(array $classification, array $fields, ?string $rawText = null, ?string $summary = null): array
    {
        $documentType = DocumentType::tryFrom((string) ($classification['document_type'] ?? '')) ?? DocumentType::OTHER;
        $text = strtolower(trim(implode(' ', array_filter([$rawText, $summary]))));

        if ($documentType === DocumentType::PAYSLIP && $this->looksLikePensionSlip($text, $fields)) {
            $classification['document_type'] = DocumentType::PENSION_SLIP->value;

            return $classification;
        }

        if ($documentType !== DocumentType::IC) {
            return $classification;
        }

        $side = $classification['ic_side'] ?? null;
        $hasFullName = filled($fields['full_name'] ?? null);
        $hasDob = filled($fields['date_of_birth'] ?? null);
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

    protected function looksLikePensionSlip(string $text, array $fields): bool
    {
        $keywords = [
            'pencen',
            'slip pencen',
            'penyata pencen',
            'pesara',
            'pesaraan',
            'retirement pension',
            'pension payment',
            'pension slip',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        $employmentType = strtolower(trim((string) ($fields['employment_type'] ?? '')));

        return filled($employmentType) && str_contains($employmentType, 'pension');
    }

    protected function fallbackReasonForAttempt(array $attempt): ?string
    {
        $result = $attempt['result'];
        $detectedType = $attempt['detected_type'];
        $fields = $result['fields'] ?? [];

        if (($result['needs_review'] ?? false) === true) {
            return 'needs_review';
        }

        if ($detectedType === DocumentType::OTHER) {
            return 'unclear_classification';
        }

        return match ($detectedType) {
            DocumentType::IC => blank($fields['ic_number'] ?? null) && blank($fields['full_name'] ?? null)
                ? 'missing_ic_identity_fields'
                : null,
            DocumentType::PAYSLIP => blank($fields['gross_income'] ?? null) && blank($fields['basic_salary'] ?? null)
                ? 'missing_payslip_income_fields'
                : null,
            default => null,
        };
    }

    protected function attemptSummary(array $attempt): array
    {
        return [
            'source' => $attempt['source'],
            'detected_document_type' => $attempt['detected_type']->value,
            'needs_review' => (bool) ($attempt['result']['needs_review'] ?? false),
            'confidence' => $attempt['result']['confidence'] ?? 'medium',
        ];
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
        data_set($metadata, 'extraction_pipeline.extraction_finished_at', now()->toIso8601String());

        $document->forceFill(['metadata' => $metadata])->save();

        return $document->lead->extractedData()->updateOrCreate(
            ['lead_document_id' => $document->id],
            [
                'document_type' => $document->document_type,
                'extracted_summary' => 'AI service is not configured. Manual review is required until GEMINI_API_KEY is set.',
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
