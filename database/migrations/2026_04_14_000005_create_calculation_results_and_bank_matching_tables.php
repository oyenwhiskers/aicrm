<?php

use App\Enums\CalculationStatus;
use App\Enums\EligibilityStatus;
use App\Enums\MatchStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lead_calculation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->decimal('total_recognized_income', 12, 2)->nullable();
            $table->decimal('total_commitments', 12, 2)->nullable();
            $table->decimal('dsr_result', 5, 2)->nullable();
            $table->decimal('allowed_financing_amount', 12, 2)->nullable();
            $table->decimal('installment', 12, 2)->nullable();
            $table->decimal('payout_result', 12, 2)->nullable();
            $table->string('eligibility_status')->default(EligibilityStatus::INCOMPLETE->value)->index();
            $table->string('calculation_status')->default(CalculationStatus::PENDING->value)->index();
            $table->json('input_snapshot')->nullable();
            $table->json('result_breakdown')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->nullable()->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('bank_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained()->cascadeOnDelete();
            $table->json('accepted_sectors')->nullable();
            $table->decimal('minimum_salary', 12, 2)->nullable();
            $table->decimal('max_loan_amount', 12, 2)->nullable();
            $table->decimal('max_dsr', 5, 2)->nullable();
            $table->json('rule_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_bank_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_id')->constrained()->cascadeOnDelete();
            $table->string('match_status')->default(MatchStatus::NOT_MATCHED->value)->index();
            $table->text('match_reason')->nullable();
            $table->unsignedInteger('priority')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();

            $table->unique(['lead_id', 'bank_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_bank_matches');
        Schema::dropIfExists('bank_rules');
        Schema::dropIfExists('banks');
        Schema::dropIfExists('lead_calculation_results');
    }
};