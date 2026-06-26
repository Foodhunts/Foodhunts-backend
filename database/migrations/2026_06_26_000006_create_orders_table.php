<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('restaurant_id')->constrained('restaurants')->restrictOnDelete();
            $table->foreignUuid('delivery_address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('payment_status')->default('pending');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->string('payment_reference')->nullable()->index();
            $table->string('payment_provider')->nullable();
            $table->uuid('payment_attempt_id')->nullable()->index();
            $table->jsonb('order_snapshot')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
