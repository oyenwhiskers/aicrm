<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiMonitoringApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_overview_breakdowns_and_request_rows(): void
    {
        $lead = Lead::query()->create([
            'name' => 'Monitoring Lead',
            'phone_number' => '+60123456789',
            'stage' => 'DOC_REQUESTED',
        ]);

        AiUsageLog::query()->create([
            'provider' => 'gemini',
            'request_context' => 'document_extraction',
            'model' => 'gemini-3.6-flash',
            'lead_id' => $lead->id,
            'document_type' => 'ic',
            'input_tokens' => 1200,
            'output_tokens' => 240,
            'total_tokens' => 1440,
            'estimated_cost' => 0.0123,
            'latency_ms' => 1600,
            'request_status' => 'success',
            'needs_review' => false,
            'review_reasons' => [],
            'request_started_at' => now()->subMinutes(10),
            'request_finished_at' => now()->subMinutes(10)->addSeconds(2),
        ]);

        AiUsageLog::query()->create([
            'provider' => 'gemini',
            'request_context' => 'lead_intake',
            'model' => 'gemini-3.5-flash-lite',
            'lead_id' => $lead->id,
            'document_type' => null,
            'input_tokens' => 800,
            'output_tokens' => 120,
            'total_tokens' => 920,
            'estimated_cost' => 0.0045,
            'latency_ms' => 900,
            'request_status' => 'review_required',
            'needs_review' => true,
            'review_reasons' => ['unclear_rows'],
            'request_started_at' => now()->subMinutes(5),
            'request_finished_at' => now()->subMinutes(5)->addSecond(),
        ]);

        $this->getJson('/api/ai-monitoring/overview')
            ->assertOk()
            ->assertJsonPath('data.totals.total_requests', 2)
            ->assertJsonPath('data.totals.input_tokens', 2000)
            ->assertJsonPath('data.totals.output_tokens', 360)
            ->assertJsonPath('data.totals.total_tokens', 2360)
            ->assertJsonPath('data.totals.review_required_requests', 1);

        $this->getJson('/api/ai-monitoring/breakdowns')
            ->assertOk()
            ->assertJsonCount(2, 'data.by_context')
            ->assertJsonCount(2, 'data.by_model');

        $this->getJson('/api/ai-monitoring/requests?request_context=document_extraction')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.request_context', 'document_extraction')
            ->assertJsonPath('data.0.document_type', 'ic');
    }
}
