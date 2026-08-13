<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Extend wallet_transactions.category to cover delivery money flows
        // (rider earnings on delivered, platform commission, delivery payout).
        DB::statement(
            "ALTER TABLE wallet_transactions
             DROP CONSTRAINT IF EXISTS wallet_transactions_category_check"
        );
        DB::statement(
            "ALTER TABLE wallet_transactions
             ADD CONSTRAINT wallet_transactions_category_check
             CHECK (category is null or category in
                ('order_payment','refund','reversal','admin_credit',
                 'delivery_earnings','commission','delivery_payout'))"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE wallet_transactions
             DROP CONSTRAINT IF EXISTS wallet_transactions_category_check"
        );
        DB::statement(
            "ALTER TABLE wallet_transactions
             ADD CONSTRAINT wallet_transactions_category_check
             CHECK (category is null or category in
                ('order_payment','refund','reversal','admin_credit'))"
        );
    }
};
