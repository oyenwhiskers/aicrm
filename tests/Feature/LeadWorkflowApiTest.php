<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeadWorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_lead_with_minimal_required_fields(): void
    {
        $response = $this->postJson('/api/leads', [
            'name' => 'Jane Doe',
            'phone_number' => '+60123456789',
            'source' => 'manual import',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonPath('data.phone_number', '+60123456789')
            ->assertJsonPath('data.stage', LeadStage::NEW_LEAD->value);

        $this->assertDatabaseHas('leads', [
            'name' => 'Jane Doe',
            'phone_number' => '+60123456789',
            'stage' => LeadStage::NEW_LEAD->value,
        ]);
    }

    public function test_it_blocks_duplicate_leads_by_phone_number(): void
    {
        Lead::query()->create([
            'name' => 'Jane Doe',
            'phone_number' => '+60123456789',
            'stage' => LeadStage::NEW_LEAD,
        ]);

        $response = $this->postJson('/api/leads', [
            'name' => 'Jane Duplicate',
            'phone_number' => '+60123456789',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    public function test_it_imports_new_leads_and_reports_duplicates(): void
    {
        Lead::query()->create([
            'name' => 'Existing Lead',
            'phone_number' => '+60111111111',
            'stage' => LeadStage::NEW_LEAD,
        ]);

        $response = $this->postJson('/api/leads/import', [
            'rows' => [
                [
                    'name' => 'Existing Lead Duplicate',
                    'phone_number' => '+60111111111',
                ],
                [
                    'name' => 'New Import Lead',
                    'phone_number' => '+60222222222',
                    'source' => 'excel',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.duplicate_count', 1);
    }

    public function test_it_uploads_documents_and_updates_completeness_and_stage(): void
    {
        Storage::fake('public');

        $lead = Lead::query()->create([
            'name' => 'Document Lead',
            'phone_number' => '+60333333333',
            'stage' => LeadStage::DOC_REQUESTED,
        ]);

        $lead->profile()->create();

        $this->postJson("/api/leads/{$lead->id}/documents", [
            'document_type' => 'ic',
            'file' => UploadedFile::fake()->image('ic.jpg'),
        ])->assertCreated()
            ->assertJsonPath('data.lead_stage', LeadStage::DOC_PARTIAL->value);

        for ($index = 1; $index <= 3; $index++) {
            $this->postJson("/api/leads/{$lead->id}/documents", [
                'document_type' => 'payslip',
                'file' => UploadedFile::fake()->create("payslip-{$index}.pdf", 200, 'application/pdf'),
            ])->assertCreated();
        }

        $this->postJson("/api/leads/{$lead->id}/documents", [
            'document_type' => 'ctos',
            'file' => UploadedFile::fake()->create('ctos.pdf', 200, 'application/pdf'),
        ])->assertCreated();

        $finalResponse = $this->postJson("/api/leads/{$lead->id}/documents", [
            'document_type' => 'ramci',
            'file' => UploadedFile::fake()->create('ramci.pdf', 200, 'application/pdf'),
        ]);

        $finalResponse->assertCreated()
            ->assertJsonPath('data.lead_stage', LeadStage::DOC_COMPLETE->value)
            ->assertJsonPath('data.document_completeness.is_complete', true);
    }

    public function test_it_returns_lead_detail_with_completeness_and_related_data(): void
    {
        $lead = Lead::query()->create([
            'name' => 'Detail Lead',
            'phone_number' => '+60444444444',
            'stage' => LeadStage::NEW_LEAD,
        ]);

        $lead->profile()->create([
            'employer' => 'ACME',
            'salary' => 3200,
        ]);

        $response = $this->getJson("/api/leads/{$lead->id}");

        $response->assertOk()
            ->assertJsonPath('data.name', 'Detail Lead')
            ->assertJsonPath('data.profile.employer', 'ACME')
            ->assertJsonStructure([
                'data' => [
                    'document_completeness' => [
                        'items',
                        'required_document_type_count',
                        'received_required_document_count',
                        'is_complete',
                        'is_partial',
                    ],
                ],
            ]);
    }
}