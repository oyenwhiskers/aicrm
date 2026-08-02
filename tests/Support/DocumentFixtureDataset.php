<?php

namespace Tests\Support;

use RuntimeException;

class DocumentFixtureDataset
{
    public const ALLOWED_DOCUMENT_TYPES = [
        'ic',
        'payslip',
        'pension_slip',
        'epf',
        'ramci',
        'ctos',
        'other',
        'mixed',
    ];

    public const ALLOWED_OUTCOMES = [
        'accepted_workflow_ready',
        'accepted_not_workflow_ready',
        'manual_review_required',
        'technical_failure',
        'unsupported_document',
    ];

    public const ALLOWED_QUALITY_TAGS = [
        'machine_generated',
        'scanned',
        'photographed',
        'rotated',
        'compressed',
        'blurred',
        'cropped',
        'multi_page',
        'mixed_document',
    ];

    public static function manifestPath(): string
    {
        return base_path('tests/Fixtures/document_intelligence/manifest.json');
    }

    public static function filesRoot(): string
    {
        return base_path('tests/Fixtures/document_intelligence/files');
    }

    public static function load(): array
    {
        $path = self::manifestPath();

        if (! is_file($path)) {
            throw new RuntimeException("Document fixture manifest not found at [{$path}].");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Document fixture manifest contains invalid JSON.');
        }

        return $decoded;
    }

    public static function fixtureAbsolutePath(array $fixture): string
    {
        return base_path('tests/Fixtures/document_intelligence/'.ltrim((string) ($fixture['file_path'] ?? ''), '/\\'));
    }
}
