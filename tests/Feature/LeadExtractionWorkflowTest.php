<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeadExtractionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_extracts_ic_document_and_updates_lead_profile_fields(): void
    {
        Storage::fake('public');
        config()->set('services.openai.api_key', 'test-key');

        Http::fake([
            '*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'IC extracted successfully.',
                                'confidence' => 'high',
                                'needs_review' => false,
                                'fields' => [
                                    'full_name' => 'Jane Doe',
                                    'ic_number' => '900101101234',
                                    'date_of_birth' => '1990-01-01',
                                    'address' => 'Kuala Lumpur',
                                ],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ]),
        ]);

        $lead = Lead::query()->create([
            'name' => 'Jane Lead',
            'phone_number' => '+60121234567',
            'stage' => LeadStage::DOC_REQUESTED,
        ]);

        $lead->profile()->create();

        $response = $this->postJson("/api/leads/{$lead->id}/documents", [
            'document_type' => 'ic',
            'file' => UploadedFile::fake()->image('ic.jpg'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.extraction.status', 'completed');

        $this->assertDatabaseHas('lead_extracted_data', [
            'lead_id' => $lead->id,
            'document_type' => 'ic',
            'extraction_status' => 'completed',
        ]);

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'ic_number' => '900101101234',
        ]);
    }

    public function test_it_marks_supported_documents_for_manual_review_when_openai_is_not_configured(): void
    {
        Storage::fake('public');
        config()->set('services.openai.api_key', null);

        $lead = Lead::query()->create([
            'name' => 'No AI Lead',
            'phone_number' => '+60128765432',
            'stage' => LeadStage::DOC_REQUESTED,
        ]);

        $lead->profile()->create();

        $response = $this->postJson("/api/leads/{$lead->id}/documents", [
            'document_type' => 'payslip',
            'file' => UploadedFile::fake()->create('payslip.pdf', 100, 'application/pdf'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.extraction.status', 'review_required');
    }
}