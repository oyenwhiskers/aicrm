<?php

namespace Tests\Feature;

use App\Services\GeminiConcurrencyService;
use App\Services\IntakeGeminiConcurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IntakeGeminiConcurrencyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_shared_global_gemini_limiter(): void
    {
        config()->set('services.gemini.global_concurrency', 4);
        config()->set('services.gemini.intake_per_batch_concurrency', 2);

        $globalLock = new \stdClass();
        $batchLock = new \stdClass();

        $sharedLimiter = Mockery::mock(GeminiConcurrencyService::class);
        $sharedLimiter->shouldReceive('leaseSeconds')->once()->andReturn(120);
        $sharedLimiter->shouldReceive('globalConcurrency')->once()->andReturn(4);
        $sharedLimiter->shouldReceive('acquireGlobal')->once()->with(4, 120)->andReturn($globalLock);
        $sharedLimiter->shouldReceive('acquireScopedSlot')->once()->with('intake-gemini:batch:55', 2, 120)->andReturn($batchLock);

        $service = new IntakeGeminiConcurrencyService($sharedLimiter);
        $lease = $service->acquire(55);

        $this->assertSame($globalLock, $lease['global']);
        $this->assertSame($batchLock, $lease['batch']);
        $this->assertSame(4, $lease['effective_global_limit']);
        $this->assertSame(2, $lease['effective_batch_limit']);
    }
}
