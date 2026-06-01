<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * catalog_badges — lookup table: Badge string → tech specs.
 *
 * Replaces regex-based parsing in the parser and normalizer.
 * Badge strings are standardised by Encar: "가솔린 2.5T 4WD"
 *
 * Populated by: php artisan catalog:build-badges
 * Used by:
 *   - Parser as fallback when detail API spec is missing
 *   - Normalizer: UPDATE lots JOIN catalog_badges ON badge = badge_kr
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_badges', function (Blueprint $table) {
            $table->id();
            $table->string('badge_kr', 150)->unique();  // "가솔린 2.5T 4WD"
            $table->string('fuel', 20)->nullable();      // gasoline|diesel|hybrid|ev|lpg
            $table->decimal('engine_volume', 4, 1)->nullable(); // 2.5
            $table->string('drive_type', 10)->nullable(); // 2wd|4wd|awd|fwd|rwd
            $table->boolean('is_turbo')->nullable();      // true if 'T' suffix
            $table->unsignedInteger('lot_count')->default(0); // how many lots use this badge
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_badges');
    }
};
