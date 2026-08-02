<?php

namespace App\Providers;

use App\Contracts\DocumentIntelligenceServiceInterface;
use App\Services\GeminiDocumentIntelligenceService;
use App\Services\PythonDocumentIntelligenceService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DocumentIntelligenceServiceInterface::class, function ($app) {
            $provider = (string) config('services.document_intelligence.provider', 'gemini');

            return match ($provider) {
                'python' => $app->make(PythonDocumentIntelligenceService::class),
                default => $app->make(GeminiDocumentIntelligenceService::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
