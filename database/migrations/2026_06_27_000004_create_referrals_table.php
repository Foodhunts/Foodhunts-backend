<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('referred_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('referral_code_used');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        // Blueprint::check() is not available in the installed framework;
        // use raw SQL so fresh databases migrate reproducibly (roadmap Phase 0.4).
        DB::statement(
            'ALTER TABLE referrals ADD CONSTRAINT referrals_referrer_diff_referred CHECK (referrer_user_id <> referred_user_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
