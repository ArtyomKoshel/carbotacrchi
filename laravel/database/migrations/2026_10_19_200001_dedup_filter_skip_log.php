<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * filter_skip_log: one row per (source, source_id, rule_name) instead of one row per hit.
 *
 * Every full parse re-logged every PRE-filtered lot (~90k rows/run, mostly the same lots).
 * The parser now upserts: skipped_at = first time this rule skipped the lot,
 * last_skipped_at = latest hit, skip_count = number of hits.
 *
 * Existing rows are collapsed into a new table, then swapped in with an atomic RENAME.
 * The newest row of each group supplies rule_id/action/field_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Leftovers of an interrupted earlier attempt.
        Schema::dropIfExists('filter_skip_log_new');
        Schema::dropIfExists('filter_skip_log_old');

        Schema::create('filter_skip_log_new', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);          // encar / kbcha
            $table->string('source_id', 100);      // external lot ID
            $table->string('lot_url', 500)->nullable();
            $table->string('rule_name', 100);      // filter rule name
            $table->unsignedInteger('rule_id')->nullable();
            $table->enum('action', ['skip', 'mark_inactive']);
            $table->string('field_name', 100)->nullable();
            $table->text('field_value')->nullable();               // value at the latest hit
            $table->timestamp('skipped_at')->useCurrent();         // first hit by this rule
            $table->timestamp('last_skipped_at')->useCurrent();    // latest hit
            $table->unsignedInteger('skip_count')->default(1);

            $table->unique(['source', 'source_id', 'rule_name'], 'uniq_source_lot_rule');
            $table->index(['source', 'skipped_at'], 'idx_source_date');
            $table->index('rule_id', 'idx_rule');
            $table->index('last_skipped_at', 'idx_last_skipped');
        });

        DB::statement(<<<'SQL'
            INSERT INTO filter_skip_log_new
                (source, source_id, lot_url, rule_name, rule_id, action, field_name, field_value,
                 skipped_at, last_skipped_at, skip_count)
            SELECT l.source, l.source_id, l.lot_url, l.rule_name, l.rule_id, l.action, l.field_name, l.field_value,
                   g.first_at, g.last_at, g.cnt
            FROM filter_skip_log l
            JOIN (
                SELECT MAX(id) AS id, MIN(skipped_at) AS first_at, MAX(skipped_at) AS last_at, COUNT(*) AS cnt
                FROM filter_skip_log
                GROUP BY source, source_id, rule_name
            ) g ON g.id = l.id
        SQL);

        DB::statement('RENAME TABLE filter_skip_log TO filter_skip_log_old, filter_skip_log_new TO filter_skip_log');
        Schema::drop('filter_skip_log_old');
    }

    public function down(): void
    {
        // Back to the append-only layout. Collapsed duplicates are not restored.
        Schema::table('filter_skip_log', function (Blueprint $table) {
            $table->dropUnique('uniq_source_lot_rule');
            $table->dropIndex('idx_last_skipped');
            $table->dropColumn(['last_skipped_at', 'skip_count']);
        });
    }
};
