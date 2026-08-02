<?php

namespace Tests\Feature;

use App\Services\GeminiExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiUsageLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_logs_document_extraction_usage(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.base_url', 'https://example.test/v1beta');
        config()->set('services.gemini.model', 'gemini-3.6-flash');

        Http::fake([
            'https://example.test/v1beta/models/gemini-3.6-flash:generateContent?key=test-key' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'summary' => 'Detected IC front.',
                                        'confidence' => 'high',
                                        'needs_review' => false,
                                        'review_reasons' => [],
                                        'classification' => [
                                            'document_type' => 'ic',
                                            'ic_side' => 'front',
                                            'statement_year' => null,
                                            'statement_month' => null,
                                            'statement_period' => null,
                                        ],
                                        'fields' => [
                                            'full_name' => 'Jane Doe',
                                            'ic_number' => '920324-01-6167',
                                        ],
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                        ],
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 1000,
                    'candidatesTokenCount' => 150,
                    'totalTokenCount' => 1150,
                ],
            ], 200),
        ]);

        $result = app(GeminiExtractionService::class)->extract(
            'image/jpeg',
            base64_encode('fake-image-binary'),
            [
                'request_context' => 'document_extraction',
                'lead_id' => 10,
                'lead_document_id' => 20,
                'input_mime_type' => 'image/jpeg',
                'input_filename' => 'ic-front.jpg',
            ],
        );

        $this->assertSame('ic', $result['classification']['document_type']);

        $this->assertDatabaseHas('ai_usage_logs', [
            'provider' => 'gemini',
            'request_context' => 'document_extraction',
            'model' => 'gemini-3.6-flash',
            'lead_id' => 10,
            'lead_document_id' => 20,
            'document_type' => 'ic',
            'input_tokens' => 1000,
            'output_tokens' => 150,
            'total_tokens' => 1150,
            'request_status' => 'success',
            'needs_review' => 0,
        ]);
    }

    public function test_it_logs_lead_intake_usage(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.base_url', 'https://example.test/v1beta');
        config()->set('services.gemini.model', 'gemini-3.6-flash');
        config()->set('services.gemini.intake_model', 'gemini-3.5-flash-lite');

        Http::fake([
            'https://example.test/v1beta/models/gemini-3.5-flash-lite:generateContent?key=test-key' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'summary' => 'Lead rows extracted.',
                                        'needs_review' => true,
                                        'rows' => [
                                            [
                                                'name' => 'Jane Doe',
                                                'phone_number' => '+60123456789',
                                            ],
                                        ],
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                        ],
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 500,
                    'candidatesTokenCount' => 80,
                    'totalTokenCount' => 580,
                ],
            ], 200),
        ]);

        $result = app(GeminiExtractionService::class)->extractLeadCaptureImage(
            'image/png',
            base64_encode('fake-intake-image'),
            [
                'request_context' => 'lead_intake',
                'input_mime_type' => 'image/png',
                'input_filename' => 'capture.png',
            ],
        );

        $this->assertTrue($result['needs_review']);

        $this->assertDatabaseHas('ai_usage_logs', [
            'provider' => 'gemini',
            'request_context' => 'lead_intake',
            'model' => 'gemini-3.5-flash-lite',
            'document_type' => null,
            'input_tokens' => 500,
            'output_tokens' => 80,
            'total_tokens' => 580,
            'request_status' => 'review_required',
            'needs_review' => 1,
        ]);
    }
}
