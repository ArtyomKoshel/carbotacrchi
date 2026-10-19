<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop lots indexes that no query needs (~68 MB, and one less B-tree per index on every
 * parser upsert). Checked 2026-10-19 with EXPLAIN / EXPLAIN ANALYZE … IGNORE INDEX on prod
 * against the queries in laravel/app and parser/repository.py:
 *
 * - lots_expires_at_index          expires_at is never written or read (always NULL)
 * - lots_updated_at_idx            no query filters/sorts on updated_at without source;
 *                                  jobEvents uses lots_source_updated_at_idx
 * - lots_source_active_fetched_idx fetched_at is written but never filtered or sorted on
 * - idx_lots_source_id             (source, id) — id is the PK; lookups use PRIMARY
 * - lots_source_index              left prefix of lots_source_updated_at_idx and the
 *                                  lots_source_active_* indexes
 * - lots_source_active_price_krw_idx  only (source, is_active) since price_krw was dropped;
 *                                  left prefix of the other lots_source_active_* indexes
 */
return new class extends Migration
{
    private const INDEXES = [
        'lots_expires_at_index'            => ['expires_at'],
        'lots_updated_at_idx'              => ['updated_at'],
        'lots_source_active_fetched_idx'   => ['source', 'is_active', 'fetched_at'],
        'idx_lots_source_id'               => ['source', 'id'],
        'lots_source_index'                => ['source'],
        'lots_source_active_price_krw_idx' => ['source', 'is_active'],
    ];

    public function up(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            if (Schema::hasIndex('lots', $name)) {
                Schema::table('lots', fn (Blueprint $table) => $table->dropIndex($name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => $columns) {
            if (!Schema::hasIndex('lots', $name)) {
                Schema::table('lots', fn (Blueprint $table) => $table->index($columns, $name));
            }
        }
    }
};
