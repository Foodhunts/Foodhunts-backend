<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Align the Laravel-side menus schema with the production Supabase schema
 * (menu_image_url is used by store apps and the R2 media migration). The
 * original migration never created this column. Idempotent: no-op where the
 * column already exists (e.g. production).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('menus', 'menu_image_url')) {
            return;
        }

        Schema::table('menus', function (Blueprint $table): void {
            $table->string('menu_image_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table): void {
            $table->dropColumn('menu_image_url');
        });
    }
};
