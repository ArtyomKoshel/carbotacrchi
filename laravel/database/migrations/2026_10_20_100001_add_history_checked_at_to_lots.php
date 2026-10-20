<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the parser last fetched a lot's history (Encar record + inspection).
 * A full run re-checks only the oldest 1/ENCAR_HISTORY_EVERY_RUNS of active
 * lots plus new ones; the rest keep their stored history (docs/04-parser.md).
 *
 * The column add is INSTANT. Backfill: lot_inspections.updated_at is written
 * on every successful record/inspection fetch, so it is the last check time.
 * Chunked by lot_inspections.id to keep each UPDATE short.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('lots', 'history_checked_at')) {
            Schema::table('lots', function (Blueprint $table) {
                $table->timestamp('history_checked_at')->nullable();
            });
        }

        $maxId = (int) DB::table('lot_inspections')->max('id');
        for ($from = 0; $from <= $maxId; $from += 20000) {
            DB::statement(
                'UPDATE lots l JOIN lot_inspections li ON li.lot_id = l.id
                 SET l.history_checked_at = li.updated_at
                 WHERE li.id > ? AND li.id <= ? AND l.history_checked_at IS NULL',
                [$from, $from + 20000]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lots', 'history_checked_at')) {
            Schema::table('lots', function (Blueprint $table) {
                $table->dropColumn('history_checked_at');
            });
        }
    }
};
