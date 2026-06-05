<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * catalog_trims — valid trim names per make.
 *
 * Used as a text-search fallback in normalization (Step 5b):
 *   lots.badge LIKE '%trim_kr%'  →  lots.trim = trim_kr
 *
 * Source: Encar iNav badge_detail (confidence 1.0 only).
 * make_kr = '*'  means the trim applies to all makes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_trims')) {
            return;
        }

        Schema::create('catalog_trims', function (Blueprint $table) {
            $table->id();
            $table->string('make_kr', 100)->default('*')->comment('Make in Korean, or * for universal');
            $table->string('trim_kr', 191);
            $table->string('trim_en', 191)->nullable();
            $table->unsignedSmallInteger('priority')->default(10)->comment('Lower = higher priority');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['make_kr', 'trim_kr']);
            $table->index('make_kr');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_trims');
    }
};
