<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rider_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('rider_id')->constrained('riders')->cascadeOnDelete();
            $table->string('document_type');      // national_id | driver_license | guarantor | passport_photo
            $table->string('storage_key');        // R2/S3 key — never store file bytes in Postgres
            $table->string('mime')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->timestamps();

            $table->index(['rider_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_documents');
    }
};
