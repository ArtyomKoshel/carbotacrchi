<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds reparse-as-job-type support:
 *  - `type` enum (full | reparse)         — distinguishes a normal source-wide job
 *                                            from a per-lot re-enrichment.
 *  - `target_lot_ids` JSON                 — list of lot IDs for reparse jobs.
 *  - extends `status` enum with 'interrupted' (used by checkpoint/auto-resume).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parse_jobs', function (Blueprint $table) {
            $table->enum('type', ['full', 'reparse'])
                ->default('full')
                ->after('source')
                ->index();
            $table->json('target_lot_ids')->nullable()->after('filters');
        });

        // Extend status enum with 'interrupted' (already used by job_worker).
        DB::statement(
            "ALTER TABLE parse_jobs MODIFY COLUMN status "
            . "ENUM('pending','running','done','error','cancelled','interrupted') "
            . "NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        Schema::table('parse_jobs', function (Blueprint $table) {
            $table->dropColumn(['type', 'target_lot_ids']);
        });

        DB::statement(
            "ALTER TABLE parse_jobs MODIFY COLUMN status "
            . "ENUM('pending','running','done','error','cancelled') "
            . "NOT NULL DEFAULT 'pending'"
        );
    }
};
