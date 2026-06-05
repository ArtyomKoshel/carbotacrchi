<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add model_group_en to catalog_models.
 * Populated from Encar iNav Metadata.EngName (official English model group names).
 * Examples: 싼타페 → "Santa Fe", 갤로퍼 → "Galloper", 그랜저 → "Grandeur"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_models', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_models', 'model_group_en')) {
                $table->string('model_group_en', 200)->nullable()->after('model_group_kr');
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalog_models', function (Blueprint $table) {
            if (Schema::hasColumn('catalog_models', 'model_group_en')) {
                $table->dropColumn('model_group_en');
            }
        });
    }
};
