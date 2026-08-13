<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->string('category')->nullable()->after('type');
            $table->string('direction')->nullable()->after('category');
            $table->string('status')->default('completed')->after('direction');
        });

        // Blueprint::check() is not available in the installed framework;
        // use raw SQL so fresh databases migrate reproducibly (roadmap Phase 0.4).
        // Constraint names must match what later migrations drop/recreate.
        DB::statement(
            "ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_type_check CHECK (type in ('credit', 'debit'))"
        );
        DB::statement(
            "ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_category_check "
            . "CHECK (category is null or category in ('order_payment', 'refund', 'reversal', 'admin_credit'))"
        );

        Schema::create('order_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('status');
            $table->foreignUuid('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });

        Schema::create('paystack_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_id')->nullable()->unique();
            $table->string('event');
            $table->string('reference')->nullable()->index();
            $table->jsonb('payload');
            $table->string('status')->default('received');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paystack_events');
        Schema::dropIfExists('order_status_history');
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropColumn(['category', 'direction', 'status']);
        });
    }
};
