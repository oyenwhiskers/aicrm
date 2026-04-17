<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('intake_batch_images', function (Blueprint $table) {
            $table->uuid('claim_token')->nullable()->after('metadata')->index();
            $table->timestamp('claimed_at')->nullable()->after('claim_token');
            $table->string('claimed_by')->nullable()->after('claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('intake_batch_images', function (Blueprint $table) {
            $table->dropColumn(['claim_token', 'claimed_at', 'claimed_by']);
        });
    }
};