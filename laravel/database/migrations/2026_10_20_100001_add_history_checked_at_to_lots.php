<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the parser last fetched a lot's history (Encar record + inspection).
 * A full run re-checks only the oldest 1/ENCAR_HISTORY_EVERY_RUNS of active
 * lots plus new ones; the rest keep their stored history (docs/04-parser.md).
 *
 * DDL only (INSTANT): migrations run before nginx starts and Railway's /up
 * healthcheck allows 30 s, which a 288k-row backfill does not fit. Backfill
 * once by hand, from lot_inspections.updated_at (written on every successful
 * record/inspection fetch), in chunks of lot_inspections.id:
 *   UPDATE lots l JOIN lot_inspections li ON li.lot_id = l.id
 *   SET l.history_checked_at = li.updated_at
 *   WHERE li.id > :a AND li.id <= :a + 100000 AND l.history_checked_at IS NULL;
 * (done on prod 2026-10-20). Lots left NULL are just fetched in full next run.
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
