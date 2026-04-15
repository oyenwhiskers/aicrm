<?php

use App\Enums\DocumentType;
use App\Enums\ExtractionStatus;
use App\Enums\UploadStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lead_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('document_type')->default(DocumentType::OTHER->value)->index();
            $table->string('original_filename');
            $table->string('storage_disk')->default('local');
            $table->string('storage_path');
            $table->string('upload_status')->default(UploadStatus::UPLOADED->value)->index();
            $table->timestamp('uploaded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_extracted_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_document_id')->constrained('lead_documents')->cascadeOnDelete();
            $table->string('document_type')->default(DocumentType::OTHER->value)->index();
            $table->text('extracted_summary')->nullable();
            $table->json('structured_fields')->nullable();
            $table->string('extraction_status')->default(ExtractionStatus::PENDING->value)->index();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_extracted_data');
        Schema::dropIfExists('lead_documents');
    }
};