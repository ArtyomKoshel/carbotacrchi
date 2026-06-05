<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add model_en to catalog_models.
 *
 * This stores the English translation of the full Encar Model name (Level 3):
 *   model_kr = '더 뉴 투싼 (NX4)'  →  model_en = 'The New Tucson (NX4)'
 *
 * Populated by: php artisan translate:run --category=model --apply
 * Used by:      lots:normalize-from-catalog  →  lots.model_en
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_models', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_models', 'model_en')) {
                $table->string('model_en', 100)->nullable()->after('model_kr');
                $table->index('model_en');
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalog_models', function (Blueprint $table) {
            if (Schema::hasColumn('catalog_models', 'model_en')) {
                $table->dropIndex(['model_en']);
                $table->dropColumn('model_en');
            }
        });
    }
};
