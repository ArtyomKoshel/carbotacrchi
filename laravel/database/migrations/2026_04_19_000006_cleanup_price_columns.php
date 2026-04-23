<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clean up price-related columns on `lots` — pure DDL.
 *
 *   1. DROP `ai_price_min`, `ai_price_max` — never used by the UI; computed
 *      externally. See parser/tests/encar_field_audit.py.
 *
 *   2. DROP `price_krw` — 100% duplicate of `price` (both stored raw KRW).
 *      The prior belief that one was in "something other than KRW" was wrong.
 *
 *   3. ADD `registration_year_month` INT UNSIGNED (format YYYYMM, e.g. 202006)
 *      — first-class indexed column for filtering ("cars registered after
 *      2020-06"). No back-fill: parser populates it on next upsert.
 */
return new class extends Migration
{
    /**
     * DDL-only. On a 226k-row table we deliberately avoid any UPDATE that
     * touches every row (especially with JSON_EXTRACT / JSON_REMOVE, which
     * read+rewrite the whole JSON blob). The new `registration_year_month`
     * column will be populated by the parser on the next upsert pass —
     * CarLot.registration_year_month is now a first-class field produced
     * by both Encar (`FormYear`) and KBCha (`parse_year_month()`), and the
     * upsert SQL uses `COALESCE(VALUES(col), col)` to set it in-place.
     *
     * DROP COLUMN is fast: MySQL 8 uses INSTANT DDL for most such drops,
     * rewriting only the table metadata, not row data.
     */
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            foreach (['ai_price_min', 'ai_price_max', 'price_krw'] as $col) {
                if (Schema::hasColumn('lots', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('lots', function (Blueprint $table) {
            if (!Schema::hasColumn('lots', 'registration_year_month')) {
                $table->unsignedInteger('registration_year_month')
                      ->nullable()
                      ->after('registration_date');
                $table->index('registration_year_month');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (Schema::hasColumn('lots', 'registration_year_month')) {
                $table->dropIndex(['registration_year_month']);
                $table->dropColumn('registration_year_month');
            }
        });
        // ai_price_* / price_krw are not restored on rollback.
    }
};
