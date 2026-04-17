<?php

use App\Enums\IntakeBatchImageStatus;
use App\Enums\IntakeBatchStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('intake_batches', function (Blueprint $table) {
            $table->id();
            $table->string('source')->nullable();
            $table->string('status')->default(IntakeBatchStatus::QUEUED->value)->index();
            $table->unsignedInteger('total_images')->default(0);
            $table->unsignedInteger('processed_images')->default(0);
            $table->unsignedInteger('successful_images')->default(0);
            $table->unsignedInteger('failed_images')->default(0);
            $table->unsignedInteger('total_rows')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('intake_batch_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_batch_id')->constrained('intake_batches')->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('storage_disk')->default('public');
            $table->string('storage_path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status')->default(IntakeBatchImageStatus::QUEUED->value)->index();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('attempts_count')->default(0);
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('intake_image_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_batch_image_id')->constrained('intake_batch_images')->cascadeOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('status')->index();
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->string('model_name')->nullable();
            $table->string('prompt_version')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('intake_extracted_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_batch_id')->constrained('intake_batches')->cascadeOnDelete();
            $table->foreignId('intake_batch_image_id')->constrained('intake_batch_images')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone_number')->index();
            $table->string('source')->nullable();
            $table->string('raw_name')->nullable();
            $table->string('raw_phone_number')->nullable();
            $table->string('confidence')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('intake_batch_normalized_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_batch_id')->constrained('intake_batches')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone_number')->index();
            $table->string('source')->nullable();
            $table->string('confidence')->nullable();
            $table->text('notes')->nullable();
            $table->json('source_images')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['intake_batch_id', 'phone_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_batch_normalized_rows');
        Schema::dropIfExists('intake_extracted_rows');
        Schema::dropIfExists('intake_image_attempts');
        Schema::dropIfExists('intake_batch_images');
        Schema::dropIfExists('intake_batches');
    }
};