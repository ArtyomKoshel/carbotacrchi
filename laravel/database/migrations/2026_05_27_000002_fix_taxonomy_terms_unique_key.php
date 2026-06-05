<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // make/model added as nullable in previous migration — change to NOT NULL DEFAULT ''
        // so they can participate in a unique key (NULL != NULL in MySQL unique indexes)
        DB::statement("ALTER TABLE taxonomy_terms MODIFY COLUMN make VARCHAR(80) NOT NULL DEFAULT ''");
        DB::statement("ALTER TABLE taxonomy_terms MODIFY COLUMN model VARCHAR(160) NOT NULL DEFAULT ''");

        // Drop old unique key (source, term_type, term)
        Schema::table('taxonomy_terms', function (Blueprint $table) {
            $table->dropUnique('taxonomy_terms_unique');
        });

        // Add new unique key that includes make + model for per-model scoping
        Schema::table('taxonomy_terms', function (Blueprint $table) {
            $table->unique(['source', 'term_type', 'make', 'model', 'term'], 'taxonomy_terms_unique');
        });
    }

    public function down(): void
    {
        Schema::table('taxonomy_terms', function (Blueprint $table) {
            $table->dropUnique('taxonomy_terms_unique');
        });

        DB::statement("ALTER TABLE taxonomy_terms MODIFY COLUMN make VARCHAR(80) NULL DEFAULT NULL");
        DB::statement("ALTER TABLE taxonomy_terms MODIFY COLUMN model VARCHAR(160) NULL DEFAULT NULL");

        Schema::table('taxonomy_terms', function (Blueprint $table) {
            $table->unique(['source', 'term_type', 'term'], 'taxonomy_terms_unique');
        });
    }
};
