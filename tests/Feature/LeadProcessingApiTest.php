<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadProcessingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_simplified_calculation_and_updates_stage(): void
    {
        $lead = Lead::query()->create([
            'name' => 'Process Lead',
            'phone_number' => '+60135550000',
            'stage' => LeadStage::DOC_COMPLETE,
        ]);

        $lead->profile()->create([
            'salary' => 4000,
            'sector' => 'government',
        ]);

        $lead->extractedData()->create([
            'lead_document_id' => $lead->documents()->create([
                'document_type' => 'payslip',
                'original_filename' => 'payslip.pdf',
                'storage_disk' => 'public',
                'storage_path' => 'leads/test/payslip.pdf',
                'upload_status' => 'uploaded',
                'uploaded_at' => now(),
            ])->id,
            'document_type' => 'payslip',
            'extracted_summary' => 'Payslip extracted.',
            'structured_fields' => [
                'fields' => [
                    'gross_income' => 4000,
                    'total_deductions' => 500,
                ],
            ],
            'extraction_status' => 'completed',
            'extracted_at' => now(),
        ]);

        $response = $this->postJson("/api/leads/{$lead->id}/calculate", []);

        $response->assertOk()
            ->assertJsonPath('data.stage', LeadStage::PROCESSED->value);
    }

    public function test_it_matches_banks_after_processing(): void
    {
        $this->seed(\Database\Seeders\BankRuleSeeder::class);

        $lead = Lead::query()->create([
            'name' => 'Match Lead',
            'phone_number' => '+60136660000',
            'stage' => LeadStage::PROCESSED,
        ]);

        $lead->profile()->create([
            'salary' => 4500,
            'sector' => 'government',
        ]);

        $lead->calculationResults()->create([
            'total_recognized_income' => 4500,
            'total_commitments' => 600,
            'dsr_result' => 13.33,
            'allowed_financing_amount' => 30000,
            'installment' => 700,
            'payout_result' => 29500,
            'eligibility_status' => 'eligible',
            'calculation_status' => 'completed',
            'processed_at' => now(),
        ]);

        $response = $this->postJson("/api/leads/{$lead->id}/match-banks");

        $response->assertOk()
            ->assertJsonPath('data.stage', LeadStage::MATCHED->value);
    }
}