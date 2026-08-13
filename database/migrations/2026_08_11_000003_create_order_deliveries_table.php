<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('rider_id')->nullable()->constrained('riders')->nullOnDelete();
            $table->string('status')->default('pending_dispatch');
            // pending_dispatch -> rider_assigned -> rider_en_route_pickup -> arrived_pickup
            //   -> picked_up -> en_route_dropoff -> arrived_dropoff -> delivered
            //   (+ failed | cancelled)
            $table->string('dispatch_mode')->default('manual'); // manual | auto
            $table->string('delivery_code', 4)->nullable();     // 4-digit PIN, generated at assignment
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->jsonb('location_snapshot')->nullable();     // rider loc at completion (dispute evidence)
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['rider_id', 'status']);
        });

        // Load-bearing safety constraint (dzpatch dispatch gate, round 2):
        // one ACTIVE trip per rider, enforced by the DB — two concurrent
        // accepts on different orders lock different rows, so only this
        // partial unique index makes a double-trip impossible.
        DB::statement(
            "CREATE UNIQUE INDEX one_active_trip_per_rider
             ON order_deliveries (rider_id)
             WHERE status IN ('rider_assigned','rider_en_route_pickup','arrived_pickup',
                              'picked_up','en_route_dropoff','arrived_dropoff')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('order_deliveries');
    }
};
