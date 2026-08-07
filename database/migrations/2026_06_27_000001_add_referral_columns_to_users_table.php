<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('referral_code')->nullable()->unique()->after('role');
            $table->foreignUuid('referred_by_user_id')->nullable()->after('referral_code')->constrained('users')->nullOnDelete();
            $table->timestamp('referred_at')->nullable()->after('referred_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('referred_by_user_id');
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['referral_code', 'referred_at']);
        });
    }
};
