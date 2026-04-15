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

        if (! $this->supports($document->document_type)) {
            return $this->storeUnsupportedResult($document);
        }

        if (blank(config('services.gemini.api_key'))) {
            return $this->storeUnavailableResult($document);
        }

        try {
            $payload = Storage::disk($document->storage_disk)->get($document->storage_path);

            $result = $this->geminiExtractionService->extract(
                $document->document_type,
                $document->metadata['mime_type'] ?? null,
                base64_encode($payload)
            );

            $record = $document->lead->extractedData()->updateOrCreate(
                ['lead_document_id' => $document->id],
                [
                    'document_type' => $document->document_type,
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

    protected function supports(DocumentType $documentType): bool
    {
        return in_array($documentType, [DocumentType::IC, DocumentType::PAYSLIP], true);
    }

    protected function storeUnsupportedResult(LeadDocument $document)
    {
        return $document->lead->extractedData()->updateOrCreate(
            ['lead_document_id' => $document->id],
            [
                'document_type' => $document->document_type,
                'extracted_summary' => 'Prototype extraction currently supports IC and payslip documents only.',
                'structured_fields' => [
                    'supported_in_prototype' => false,
                ],
                'extraction_status' => ExtractionStatus::REVIEW_REQUIRED,
                'extracted_at' => now(),
            ]
        );
    }

    protected function storeUnavailableResult(LeadDocument $document)
    {
        return $document->lead->extractedData()->updateOrCreate(
            ['lead_document_id' => $document->id],
            [
                'document_type' => $document->document_type,
                'extracted_summary' => 'Gemini is not configured. Manual review is required until GEMINI_API_KEY is set.',
                'structured_fields' => [
                    'supported_in_prototype' => true,
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