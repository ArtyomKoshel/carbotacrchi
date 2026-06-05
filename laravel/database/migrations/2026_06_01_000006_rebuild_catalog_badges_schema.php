<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuild catalog_badges with correct composite key.
 *
 * Problem with the original schema: badge_kr UNIQUE assumes one badge string
 * encodes the same specs across all models. This is wrong — "9인승 시그니처"
 * is Carnival-specific (diesel 2.2L) but the badge string alone carries no
 * engine info. A composite (make_kr, model_group_kr, badge_kr) key is correct.
 *
 * New columns:
 *   make_kr        — from iNav taxonomy (e.g. "기아")
 *   model_group_kr — from iNav taxonomy (e.g. "카니발")
 *   seat_count     — extracted from badge_kr "N인승" at catalog build time
 *   trim_kr        — badge_detail_kr from iNav (e.g. "프레스티지", "시그니처")
 *
 * Populated by: php artisan catalog:build-badges {file} --apply
 * Used by:      php artisan lots:normalize-from-catalog --apply
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop and recreate — table was just created empty in migration 000002.
        Schema::dropIfExists('catalog_badges');

        Schema::create('catalog_badges', function (Blueprint $table) {
            $table->id();
            $table->string('make_kr', 100);                          // "기아"
            $table->string('model_group_kr', 200);                   // "카니발"
            $table->string('badge_kr', 150);                         // "9인승 시그니처"
            $table->string('fuel', 20)->nullable();                   // gasoline|diesel|hybrid|ev|lpg
            $table->decimal('engine_volume', 4, 1)->nullable();       // 2.2
            $table->string('drive_type', 10)->nullable();             // fwd|rwd|awd
            $table->boolean('is_turbo')->nullable();
            $table->unsignedTinyInteger('seat_count')->nullable();    // 9, 11, 7
            $table->string('trim_kr', 150)->nullable();               // "프레스티지", null if in badge itself
            $table->unsignedInteger('lot_count')->default(0);
            $table->timestamps();

            $table->unique(['make_kr', 'model_group_kr', 'badge_kr']);
            $table->index('make_kr');
            $table->index(['make_kr', 'model_group_kr']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_badges');

        // Restore original single-key schema
        Schema::create('catalog_badges', function (Blueprint $table) {
            $table->id();
            $table->string('badge_kr', 150)->unique();
            $table->string('fuel', 20)->nullable();
            $table->decimal('engine_volume', 4, 1)->nullable();
            $table->string('drive_type', 10)->nullable();
            $table->boolean('is_turbo')->nullable();
            $table->unsignedInteger('lot_count')->default(0);
            $table->timestamps();
        });
    }
};
