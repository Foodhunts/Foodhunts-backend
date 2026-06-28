<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('buy_for_me_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('token')->unique();
            $table->foreignUuid('requester_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('restaurant_id')->constrained('restaurants')->restrictOnDelete();
            $table->jsonb('cart_snapshot');
            $table->jsonb('delivery_address_snapshot')->nullable();
            $table->foreignUuid('delivery_address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('delivery_fee', 12, 2);
            $table->decimal('service_fee', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->string('currency', 3)->default('NGN');
            $table->text('message')->nullable();
            $table->string('payer_name')->nullable();
            $table->string('payer_email')->nullable();
            $table->string('payer_phone')->nullable();
            $table->string('payment_reference')->nullable()->unique();
            $table->string('payment_provider')->nullable();
            $table->string('status')->default('pending');
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buy_for_me_requests');
    }
};
