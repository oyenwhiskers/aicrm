<?php

use App\Enums\LeadStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lead_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('action_type')->index();
            $table->text('action_detail');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('lead_stage_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('old_stage')->nullable()->default(LeadStage::NEW_LEAD->value);
            $table->string('new_stage')->index();
            $table->timestamp('changed_at')->useCurrent();
            $table->text('note')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_stage_histories');
        Schema::dropIfExists('lead_activity_logs');
    }
};