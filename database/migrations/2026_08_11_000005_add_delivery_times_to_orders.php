<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('estimated_delivery_time')->nullable()->after('total_amount');
            $table->timestamp('actual_delivery_time')->nullable()->after('estimated_delivery_time');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['estimated_delivery_time', 'actual_delivery_time']);
        });
    }
};
