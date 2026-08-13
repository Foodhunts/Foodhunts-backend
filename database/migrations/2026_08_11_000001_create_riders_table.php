<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('riders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('vehicle_type')->default('motorcycle'); // motorcycle | bicycle | car
            $table->string('plate_number')->nullable();
            $table->string('kyc_status')->default('not_started');
            $table->string('kyc_reviewer_note')->nullable();
            $table->boolean('is_online')->default(false);
            // Latest-wins location: one row per rider, never a per-ping history
            // (cost rule from docs/delivery-feature-implementation.md §8).
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('location_updated_at')->nullable();
            $table->decimal('rating', 3, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_online', 'kyc_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riders');
    }
};
