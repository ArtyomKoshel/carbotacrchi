<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * lots_archive keeps a sold lot's place in the reference catalog: its ids, and the names as
 * they were (the catalog's from now on; rows archived before keep the old translations).
 * Renames and nullable columns at the end are instant in MySQL 8.0.29+ — nothing rebuilt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots_archive', function (Blueprint $t) {
            $t->renameColumn('make_en', 'make_name');
            $t->renameColumn('model_group_en', 'model_name');
            $t->renameColumn('model_en', 'generation_name');
        });
        Schema::table('lots_archive', function (Blueprint $t) {
            $t->unsignedSmallInteger('ref_make_id')->nullable();
            $t->unsignedInteger('ref_model_id')->nullable();
            $t->unsignedInteger('ref_generation_id')->nullable();
            $t->unsignedInteger('ref_modification_id')->nullable();
            $t->unsignedInteger('ref_complectation_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lots_archive', function (Blueprint $t) {
            $t->dropColumn(['ref_make_id', 'ref_model_id', 'ref_generation_id', 'ref_modification_id', 'ref_complectation_id']);
        });
        Schema::table('lots_archive', function (Blueprint $t) {
            $t->renameColumn('make_name', 'make_en');
            $t->renameColumn('model_name', 'model_group_en');
            $t->renameColumn('generation_name', 'model_en');
        });
    }
};
