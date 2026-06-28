<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('referral_rewards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('reward_percentage', 5, 2)->default(5.00);
            $table->decimal('order_amount', 12, 2);
            $table->decimal('reward_amount', 12, 2);
            $table->foreignUuid('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'referrer_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
    }
};
