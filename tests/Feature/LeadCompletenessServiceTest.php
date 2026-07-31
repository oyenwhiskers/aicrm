<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Models\Lead;
use App\Services\LeadCompletenessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadCompletenessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_assigns_three_consecutive_payslips_to_checklist_slots(): void
    {
        $lead = Lead::query()->create([
            'name' => 'Checklist Lead',
            'phone_number' => '+60129990000',
            'stage' => LeadStage::DOC_PARTIAL,
        ]);

        $lead->profile()->create();

        foreach (['2026-04', '2026-05', '2026-06'] as $period) {
            $lead->documents()->create([
                'document_type' => 'payslip',
                'original_filename' => "payslip-{$period}.pdf",
                'storage_disk' => 'public',
                'storage_path' => "leads/{$lead->id}/documents/payslip-{$period}.pdf",
                'upload_status' => 'uploaded',
                'uploaded_at' => now(),
                'metadata' => [
                    'effective_document_type' => 'payslip',
                    'classification' => [
                        'document_type' => 'payslip',
                        'statement_period' => $period,
                        'statement_year' => (int) substr($period, 0, 4),
                        'statement_month' => (int) substr($period, 5, 2),
                        'needs_review' => false,
                    ],
                ],
            ]);
        }

        $summary = app(LeadCompletenessService::class)->summarize($lead->fresh('documents'));
        $payslipGroup = collect($summary['items'])->firstWhere('document_type', 'payslip');
        $slots = collect($payslipGroup['slots'] ?? [])->keyBy('key');

        $this->assertSame(3, $payslipGroup['received_count']);
        $this->assertFalse($slots['payslip_1']['is_missing']);
        $this->assertFalse($slots['payslip_2']['is_missing']);
        $this->assertFalse($slots['payslip_3']['is_missing']);
        $this->assertSame('2026-04', $slots['payslip_1']['detail']);
        $this->assertSame('2026-05', $slots['payslip_2']['detail']);
        $this->assertSame('2026-06', $slots['payslip_3']['detail']);
    }

    public function test_pension_slip_does_not_take_a_payslip_checklist_slot(): void
    {
        $lead = Lead::query()->create([
            'name' => 'Checklist Lead',
            'phone_number' => '+60129990001',
            'stage' => LeadStage::DOC_PARTIAL,
        ]);

        $lead->profile()->create();

        $pensionSlip = $lead->documents()->create([
            'document_type' => 'pension_slip',
            'original_filename' => 'slip-pencen-2026-04.jpg',
            'storage_disk' => 'public',
            'storage_path' => "leads/{$lead->id}/documents/slip-pencen-2026-04.jpg",
            'upload_status' => 'uploaded',
            'uploaded_at' => now(),
            'metadata' => [
                'effective_document_type' => 'pension_slip',
                'classification' => [
                    'document_type' => 'pension_slip',
                    'statement_period' => '2026-04',
                    'statement_year' => 2026,
                    'statement_month' => 4,
                    'needs_review' => false,
                ],
            ],
        ]);

        foreach (['2026-04', '2026-05', '2026-06'] as $period) {
            $lead->documents()->create([
                'document_type' => 'payslip',
                'original_filename' => "payslip-{$period}.pdf",
                'storage_disk' => 'public',
                'storage_path' => "leads/{$lead->id}/documents/payslip-{$period}.pdf",
                'upload_status' => 'uploaded',
                'uploaded_at' => now(),
                'metadata' => [
                    'effective_document_type' => 'payslip',
                    'classification' => [
                        'document_type' => 'payslip',
                        'statement_period' => $period,
                        'statement_year' => (int) substr($period, 0, 4),
                        'statement_month' => (int) substr($period, 5, 2),
                        'needs_review' => false,
                    ],
                ],
            ]);
        }

        $summary = app(LeadCompletenessService::class)->summarize($lead->fresh('documents'));
        $payslipGroup = collect($summary['items'])->firstWhere('document_type', 'payslip');
        $slots = collect($payslipGroup['slots'] ?? [])->keyBy('key');
        $assignments = app(LeadCompletenessService::class)->documentAssignmentKeys($lead->fresh('documents'));

        $this->assertSame(3, $payslipGroup['received_count']);
        $this->assertSame('2026-04', $slots['payslip_1']['detail']);
        $this->assertSame('2026-05', $slots['payslip_2']['detail']);
        $this->assertSame('2026-06', $slots['payslip_3']['detail']);
        $this->assertArrayNotHasKey($pensionSlip->id, $assignments);
    }

    public function test_it_assigns_payslips_from_classification_when_stored_type_is_stale(): void
    {
        $lead = Lead::query()->create([
            'name' => 'Checklist Lead',
            'phone_number' => '+60129990002',
            'stage' => LeadStage::DOC_PARTIAL,
        ]);

        $lead->profile()->create();

        foreach (['2026-04', '2026-05', '2026-06'] as $period) {
            $lead->documents()->create([
                'document_type' => 'other',
                'original_filename' => "payslip-{$period}.pdf",
                'storage_disk' => 'public',
                'storage_path' => "leads/{$lead->id}/documents/payslip-{$period}.pdf",
                'upload_status' => 'uploaded',
                'uploaded_at' => now(),
                'metadata' => [
                    'classification' => [
                        'document_type' => 'payslip',
                        'statement_period' => $period,
                        'statement_year' => (int) substr($period, 0, 4),
                        'statement_month' => (int) substr($period, 5, 2),
                        'needs_review' => false,
                    ],
                ],
            ]);
        }

        $summary = app(LeadCompletenessService::class)->summarize($lead->fresh('documents'));
        $payslipGroup = collect($summary['items'])->firstWhere('document_type', 'payslip');
        $slots = collect($payslipGroup['slots'] ?? [])->keyBy('key');

        $this->assertSame(3, $payslipGroup['received_count']);
        $this->assertSame('2026-04', $slots['payslip_1']['detail']);
        $this->assertSame('2026-05', $slots['payslip_2']['detail']);
        $this->assertSame('2026-06', $slots['payslip_3']['detail']);
    }
}
