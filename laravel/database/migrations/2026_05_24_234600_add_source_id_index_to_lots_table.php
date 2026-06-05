<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('lots')) {
            return;
        }

        $indexExists = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'lots')
            ->where('index_name', 'idx_lots_source_id')
            ->exists();

        if ($indexExists) {
            return;
        }

        Schema::table('lots', function (Blueprint $table) {
            $table->index(['source', 'id'], 'idx_lots_source_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('lots')) {
            return;
        }

        $indexExists = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'lots')
            ->where('index_name', 'idx_lots_source_id')
            ->exists();

        if (!$indexExists) {
            return;
        }

        Schema::table('lots', function (Blueprint $table) {
            $table->dropIndex('idx_lots_source_id');
        });
    }
};
