<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox for Dzpatch webhooks.
 *
 * Every accepted webhook is recorded here before its effect is applied. Dzpatch
 * retries on any non-2xx response, so the same event will legitimately arrive
 * more than once; `dzpatch_event_id` is unique so the second arrival is a
 * database conflict rather than a duplicated state change.
 *
 * Rows are kept after processing. When a delivery ends up in a state nobody
 * expects, the ordered event history is the only record of how it got there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Nullable: an event can arrive for a delivery we have no row for
            // (a dispatch that failed locally after Dzpatch accepted it). Those
            // are still stored so the orphan is visible rather than discarded.
            $table->foreignUuid('delivery_id')
                ->nullable()
                ->constrained('deliveries')
                ->cascadeOnDelete();

            // Dzpatch's X-Dzpatch-Event-Id. The idempotency key for the inbox.
            $table->string('dzpatch_event_id')->unique();

            $table->string('event_type');
            $table->string('status')->nullable();
            $table->unsignedBigInteger('sequence')->default(0);

            $table->string('external_order_id')->nullable()->index();

            $table->jsonb('payload');

            // `received` -> `processed` | `ignored` | `failed`.
            // `ignored` covers a stale sequence: valid, verified, but superseded.
            $table->string('processing_status')->default('received')->index();
            $table->text('processing_error')->nullable();

            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['delivery_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_events');
    }
};
