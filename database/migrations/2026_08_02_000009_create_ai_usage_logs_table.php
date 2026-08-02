<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->index();
            $table->string('request_context')->index();
            $table->string('model')->index();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_document_id')->nullable()->constrained('lead_documents')->nullOnDelete();
            $table->string('document_type')->nullable()->index();
            $table->string('input_mime_type')->nullable();
            $table->string('input_filename')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('estimated_cost', 12, 6)->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable()->index();
            $table->string('request_status')->index();
            $table->boolean('needs_review')->default(false)->index();
            $table->json('review_reasons')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('request_started_at')->index();
            $table->dateTime('request_finished_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
