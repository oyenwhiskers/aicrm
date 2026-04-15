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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone_number')->index();
            $table->string('ic_number')->nullable()->index();
            $table->string('source')->nullable()->index();
            $table->string('stage')->default(LeadStage::NEW_LEAD->value)->index();
            $table->timestamps();
        });

        Schema::create('lead_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('employer')->nullable();
            $table->string('sector')->nullable()->index();
            $table->string('employment_type')->nullable();
            $table->decimal('salary', 12, 2)->nullable();
            $table->decimal('other_income', 12, 2)->nullable();
            $table->unsignedTinyInteger('age')->nullable();
            $table->decimal('years_of_service', 5, 2)->nullable();
            $table->boolean('is_pensioner')->default(false);
            $table->boolean('has_akpk')->default(false);
            $table->boolean('is_blacklisted')->default(false);
            $table->boolean('has_bnpl')->default(false);
            $table->boolean('has_legal_or_saa_issue')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_profiles');
        Schema::dropIfExists('leads');
    }
};