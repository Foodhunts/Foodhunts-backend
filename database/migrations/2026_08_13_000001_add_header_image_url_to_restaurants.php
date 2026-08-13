<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Align the Laravel-side restaurants schema with the production Supabase
 * schema, where the cover/header image column is named header_image_url
 * (used by all store apps and the media upload path). The original migration
 * created cover_image_url; that column is left untouched (never edit an
 * applied migration). Idempotent so it is a no-op on databases that already
 * have the column (e.g. production).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('restaurants', 'header_image_url')) {
            return;
        }

        Schema::table('restaurants', function (Blueprint $table): void {
            $table->string('header_image_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn('header_image_url');
        });
    }
};
