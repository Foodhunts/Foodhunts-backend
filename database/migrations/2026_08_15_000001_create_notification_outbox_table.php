<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->string('title');
            $table->string('body');
            $table->jsonb('data')->nullable();
            $table->string('dedup_key')->nullable();
            $table->string('status')->default('pending'); // pending | sending | sent | failed | dead
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            // Postgres treats NULLs as distinct, so rows without a dedup_key
            // never collide; rows with one are de-duplicated automatically.
            $table->unique('dedup_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
    }
};
