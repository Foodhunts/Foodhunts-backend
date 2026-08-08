<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Align local delivery identity with the Dzpatch v1 API.
 *
 * v1 identifies a delivery by (external_order_id, dispatch_attempt) rather than
 * external_order_id alone, because one order may make several rider-search
 * attempts after no rider is found. Previously a retry had to append a random
 * suffix to external_order_id to escape the unique constraint, which made the
 * value stop being a true reference to our order and complicated reconciliation.
 *
 * external_order_id therefore becomes unique *per attempt* instead of globally.
 * The pair is still unique, so two local rows can never claim the same remote
 * delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->unsignedInteger('dispatch_attempt')->default(1)->after('external_order_id');
        });

        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropUnique(['external_order_id']);
            $table->unique(['external_order_id', 'dispatch_attempt']);
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropUnique(['external_order_id', 'dispatch_attempt']);
            $table->unique(['external_order_id']);
            $table->dropColumn('dispatch_attempt');
        });
    }
};
