<?php

namespace Tests\Feature;

use App\Contracts\DocumentIntelligenceServiceInterface;
use App\Services\GeminiDocumentIntelligenceService;
use App\Services\PythonDocumentIntelligenceService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentIntelligenceProviderTest extends TestCase
{
    public function test_it_uses_gemini_document_provider_by_default(): void
    {
        config()->set('services.document_intelligence.provider', 'gemini');

        $provider = $this->app->make(DocumentIntelligenceServiceInterface::class);

        $this->assertInstanceOf(GeminiDocumentIntelligenceService::class, $provider);
    }

    public function test_it_can_resolve_python_document_provider(): void
    {
        config()->set('services.document_intelligence.provider', 'python');
        config()->set('services.document_intelligence.python.base_url', 'http://python-doc-service:8000');

        $provider = $this->app->make(DocumentIntelligenceServiceInterface::class);

        $this->assertInstanceOf(PythonDocumentIntelligenceService::class, $provider);
        $this->assertTrue($provider->isConfigured());
    }

    public function test_python_provider_posts_expected_contract(): void
    {
        config()->set('services.document_intelligence.python.base_url', 'http://python-doc-service:8000');
        config()->set('services.document_intelligence.python.extract_path', '/extract');
        config()->set('services.document_intelligence.shared_storage.enabled_disks', ['public']);
        config()->set('services.document_intelligence.shared_storage.disk_roots.public', '/srv/lps/storage/app/public');

        Http::fake([
            'http://python-doc-service:8000/extract' => Http::response([
                'summary' => 'Python extraction completed.',
                'confidence' => 'high',
                'needs_review' => false,
                'review_reasons' => [],
                'classification' => [
                    'document_type' => 'payslip',
                    'ic_side' => null,
                    'statement_year' => 2026,
                    'statement_month' => 4,
                    'statement_period' => '2026-04',
                ],
                'fields' => [
                    'gross_income' => 3200.50,
                ],
                'raw_text' => 'PAYSLIP APR 2026',
                'provider_meta' => [
                    'provider' => 'python_local',
                ],
            ], 200),
        ]);

        $provider = app(PythonDocumentIntelligenceService::class);
        $result = $provider->extractDocument([
            'document_id' => 123,
            'lead_id' => 10,
            'filename' => 'payslip-apr.pdf',
            'mime_type' => 'application/pdf',
            'storage_disk' => 'public',
            'storage_path' => 'leads/10/documents/payslip/payslip-apr.pdf',
            'source' => 'original',
            'mode' => 'primary',
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $contentType = implode(';', $request->header('Content-Type'));

            return $request->url() === 'http://python-doc-service:8000/extract'
                && str_contains($contentType, 'application/json')
                && ($data['document_id'] ?? null) == 123
                && ($data['lead_id'] ?? null) == 10
                && ($data['filename'] ?? null) === 'payslip-apr.pdf'
                && ($data['mime_type'] ?? null) === 'application/pdf'
                && ($data['storage_disk'] ?? null) === 'public'
                && ($data['storage_path'] ?? null) === 'leads/10/documents/payslip/payslip-apr.pdf'
                && ($data['source'] ?? null) === 'original'
                && ($data['mode'] ?? null) === 'primary'
                && ($data['shared_storage_roots']['public'] ?? null) === '/srv/lps/storage/app/public'
                && ($data['allowed_storage_disks'] ?? null) === ['public'];
        });

        $this->assertSame('payslip', $result['classification']['document_type']);
        $this->assertSame('2026-04', $result['classification']['statement_period']);
        $this->assertSame(3200.50, $result['fields']['gross_income']);
    }
}
