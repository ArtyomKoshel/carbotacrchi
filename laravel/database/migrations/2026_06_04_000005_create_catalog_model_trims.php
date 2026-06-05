<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * catalog_model_trims — curated trim mapping scoped by make + model_group.
 *
 * Matching strategy is strict (100% exact):
 *   - by badge_exact OR
 *   - by badge_group_exact
 *
 * Intended usage:
 *   - populate lots.trim when parser BadgeDetail is missing
 *   - keep catalog_badges as raw iNav-derived source
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_model_trims')) {
            return;
        }

        Schema::create('catalog_model_trims', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32)->default('encar')->index();
            $table->string('make_kr', 100)->index();
            $table->string('model_group_kr', 200)->index();
            $table->string('badge_exact', 191)->nullable();
            $table->string('badge_group_exact', 191)->nullable();
            $table->string('trim_kr', 191);
            $table->string('trim_en', 191)->nullable();
            $table->string('origin', 32)->default('manual')->index();
            $table->decimal('confidence', 5, 2)->default(1.00);
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            // unique key added in 2026_06_05_000002_fix_catalog_model_trims_unique_key.php
            // using prefix lengths to stay under MySQL 3072-byte limit
            $table->index(['source', 'make_kr', 'model_group_kr'], 'catalog_model_trims_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_model_trims');
    }
};
