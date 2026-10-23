<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the catalog filters: make → model → generation, and modification on its own
 * (a picker may pick only "530i xDrive"). Complectation rides on the model's rows.
 * Online (INPLACE, no lock): the parser and the API keep writing and reading meanwhile.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('lots', 'lots_ref_make_model_generation_index')) {
            DB::statement('ALTER TABLE lots ADD INDEX lots_ref_make_model_generation_index (ref_make_id, ref_model_id, ref_generation_id), ALGORITHM=INPLACE, LOCK=NONE');
        }
        if (! Schema::hasIndex('lots', 'lots_ref_modification_id_index')) {
            DB::statement('ALTER TABLE lots ADD INDEX lots_ref_modification_id_index (ref_modification_id), ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    public function down(): void
    {
        foreach (['lots_ref_make_model_generation_index', 'lots_ref_modification_id_index'] as $index) {
            if (Schema::hasIndex('lots', $index)) {
                DB::statement("ALTER TABLE lots DROP INDEX {$index}");
            }
        }
    }
};
