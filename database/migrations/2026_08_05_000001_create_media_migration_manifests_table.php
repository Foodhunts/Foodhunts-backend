<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_migration_manifests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('source_url');
            $table->string('source_provider', 32);
            $table->text('source_path')->nullable();
            $table->text('destination_key')->nullable();
            $table->text('destination_url')->nullable();
            $table->string('model_type', 64);
            $table->uuid('model_id');
            $table->string('field_name', 64);
            $table->string('status', 16);
            $table->string('checksum', 64)->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('migrated_at')->nullable();
            $table->timestamps();

            $table->index(['model_type', 'model_id', 'field_name']);
            $table->index(['status', 'model_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_migration_manifests');
    }
};
