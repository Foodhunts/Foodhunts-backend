<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery dispatch to Dzpatch.
 *
 * One row per dispatch attempt of a FoodHunts order to the Dzpatch partner API.
 * The `orders` table is deliberately left untouched: an order's delivery state
 * is derived from here, so a failed dispatch never corrupts the order itself.
 *
 * Status values mirror Dzpatch's PartnerDeliveryStatus exactly rather than being
 * re-mapped locally. Translating them here would mean two sources of truth for
 * the same fact, and a webhook carrying a status we had not anticipated would be
 * silently dropped. Storing them verbatim means an unknown status is visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            // Dzpatch's identifiers. Null until the create call succeeds, which
            // is why a dispatch row can exist in `pending` with neither set.
            $table->uuid('dzpatch_delivery_id')->nullable()->unique();
            $table->string('dzpatch_order_id')->nullable();

            // What we sent as external_order_id. Unique because Dzpatch treats a
            // repeat of the same value as the same delivery, so we must not be
            // able to generate two local rows claiming the same remote identity.
            $table->string('external_order_id')->unique();

            // `pending` and `dispatch_failed` are ours; every other value comes
            // from Dzpatch verbatim.
            $table->string('status')->default('pending')->index();

            $table->string('delivery_code')->nullable();
            $table->string('delivery_code_status')->nullable();

            $table->decimal('submitted_fee', 12, 2)->nullable();
            $table->decimal('applied_fee', 12, 2)->nullable();
            $table->string('pricing_source')->nullable();
            $table->string('currency', 3)->default('NGN');

            // Rider details arrive by webhook, after assignment.
            $table->string('rider_name')->nullable();
            $table->string('rider_phone')->nullable();
            $table->decimal('rider_lat', 10, 7)->nullable();
            $table->decimal('rider_lng', 10, 7)->nullable();
            $table->timestamp('rider_location_updated_at')->nullable();

            $table->string('tracking_url')->nullable();

            // The key sent on create. Retrying a dispatch reuses it so Dzpatch
            // returns the original delivery instead of creating a second one.
            $table->string('idempotency_key')->unique();

            // Monotonic per delivery. Webhooks can arrive out of order, so an
            // event whose sequence is not greater than this one is ignored.
            $table->unsignedBigInteger('last_event_sequence')->default(0);

            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
