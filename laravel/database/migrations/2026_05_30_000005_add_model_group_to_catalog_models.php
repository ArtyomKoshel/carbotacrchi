<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add model_group_kr to catalog_models.
 *
 * Previously catalog_models only stored (make_kr, make_en, model_kr) where
 * model_kr = Encar 등급 level (e.g. "쏘나타(DN8)19-23").
 * ModelGroup (e.g. "쏘나타") was never stored — it was blocklisted from raw_data.
 *
 * This column adds the intermediate hierarchy level:
 *   make_kr → model_group_kr → model_kr
 *
 * GradeExtractorService does not use model_group_kr for matching — it only
 * needs model_kr for substring matching. The column is used for UI navigation
 * and catalog browsing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_models', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_models', 'model_group_kr')) {
                $table->string('model_group_kr', 200)->nullable()->after('make_en');
                $table->index('model_group_kr');
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalog_models', function (Blueprint $table) {
            if (Schema::hasColumn('catalog_models', 'model_group_kr')) {
                $table->dropIndex(['model_group_kr']);
                $table->dropColumn('model_group_kr');
            }
        });
    }
};
