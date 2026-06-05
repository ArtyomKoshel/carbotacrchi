<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add generation column to catalog_models.
 *
 * Stores the generation/chassis suffix extracted from model_kr:
 *   "카니발 4세대"  → generation = "4세대"
 *   "그랜저 (GN7)" → generation = "GN7"
 *   "i30 (PD)"     → generation = "PD"
 *
 * Populated by: php artisan catalog:import-from-inav --apply
 * Used by: cascading filter UI (make → model_group → generation → badge)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_models', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_models', 'generation')) {
                $table->string('generation', 40)->nullable()->after('model_kr');
                $table->index('generation');
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalog_models', function (Blueprint $table) {
            if (Schema::hasColumn('catalog_models', 'generation')) {
                $table->dropIndex(['generation']);
                $table->dropColumn('generation');
            }
        });
    }
};
