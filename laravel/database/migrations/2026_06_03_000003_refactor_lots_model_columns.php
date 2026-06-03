<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align lots columns with Encar iNav hierarchy:
 *
 *   Encar level        Old name      New name
 *   ──────────────     ──────────    ────────────────
 *   ModelGroup (EN)    model_en      model_group_en
 *   Model / Level 3    (none)        model_en  ← new, filled by translate:run
 *
 * Drop columns that are always NULL and serve no purpose:
 *   variant, package, cylinders
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Step 1: add model_group_en, copy data from model_en ───────────────
        Schema::table('lots', function (Blueprint $table) {
            if (! Schema::hasColumn('lots', 'model_group_en')) {
                $table->string('model_group_en', 100)->nullable()->after('model_en');
                $table->index('model_group_en');
            }
        });

        DB::statement('UPDATE lots SET model_group_en = model_en WHERE model_group_en IS NULL AND model_en IS NOT NULL');

        // ── Step 2: drop old model_en, add new model_en (Level 3) ─────────────
        Schema::table('lots', function (Blueprint $table) {
            $table->dropColumn('model_en');
        });

        Schema::table('lots', function (Blueprint $table) {
            $table->string('model_en', 100)->nullable()->after('model');
            $table->index('model_en');
        });

        // ── Step 3: drop always-NULL columns ──────────────────────────────────
        Schema::table('lots', function (Blueprint $table) {
            $cols = ['variant', 'package', 'cylinders'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('lots', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        // Restore model_en from model_group_en
        Schema::table('lots', function (Blueprint $table) {
            if (! Schema::hasColumn('lots', 'model_en')) {
                $table->string('model_en', 100)->nullable()->after('model_group_en');
                $table->index('model_en');
            }
        });

        DB::statement('UPDATE lots SET model_en = model_group_en WHERE model_en IS NULL');

        Schema::table('lots', function (Blueprint $table) {
            $table->dropColumn('model_group_en');
            $table->dropColumn('model_en'); // the new Level-3 one
        });

        Schema::table('lots', function (Blueprint $table) {
            $table->string('variant', 100)->nullable();
            $table->string('package', 100)->nullable();
            $table->tinyInteger('cylinders')->unsigned()->nullable();
        });
    }
};
