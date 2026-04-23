<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            // Speeds up ORDER BY price with source/active filter
            $table->index(['source', 'is_active', 'price'], 'lots_source_active_price_idx');
            // Speeds up ORDER BY price_krw
            $table->index(['source', 'is_active', 'price_krw'], 'lots_source_active_price_krw_idx');
            // Speeds up ORDER BY fetched_at (recency sort, stale detection)
            $table->index(['source', 'is_active', 'fetched_at'], 'lots_source_active_fetched_idx');
            // Speeds up make/model filter + active status
            $table->index(['source', 'is_active', 'make', 'model'], 'lots_source_active_make_model_idx');
            // Speeds up ORDER BY updated_at (admin listing, dashboard, direct DB queries)
            $table->index('updated_at', 'lots_updated_at_idx');
            // Speeds up WHERE source=? AND updated_at>=? ORDER BY updated_at (jobEvents)
            $table->index(['source', 'updated_at'], 'lots_source_updated_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropIndex('lots_source_active_price_idx');
            // price_krw column may already be dropped by a later migration —
            // MySQL removes indexes automatically when the column is dropped.
            if (Schema::hasColumn('lots', 'price_krw')) {
                $table->dropIndex('lots_source_active_price_krw_idx');
            }
            $table->dropIndex('lots_source_active_fetched_idx');
            $table->dropIndex('lots_source_active_make_model_idx');
            $table->dropIndex('lots_updated_at_idx');
            $table->dropIndex('lots_source_updated_at_idx');
        });
    }
};
