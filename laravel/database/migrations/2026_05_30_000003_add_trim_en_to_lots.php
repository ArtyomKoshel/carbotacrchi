<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promote grade_detail_en from raw_data JSON to a first-class column.
 *
 * Source: Encar detail API → category.gradeDetailEnglishName
 * Already stored in raw_data->>'$.grade_detail_en' for lots whose detail was fetched.
 *
 * After this migration the parser will write trim_en directly to the column
 * and the blocklist in CarLot._RAW_DATA_BLOCKLIST prevents it from going into raw_data again.
 * grade_detail_kr is also removed — it duplicates lots.trim (= badge_detail_kr).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (! Schema::hasColumn('lots', 'trim_en')) {
                $table->string('trim_en', 150)->nullable()->after('trim');
            }
        });

        // Back-fill from existing raw_data where the detail API was already called.
        // Runs in one statement — no row-by-row locking.
        DB::statement(<<<'SQL'
            UPDATE lots
            SET trim_en = JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.grade_detail_en'))
            WHERE JSON_EXTRACT(raw_data, '$.grade_detail_en') IS NOT NULL
              AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.grade_detail_en')) != ''
              AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.grade_detail_en')) != 'null'
              AND trim_en IS NULL
        SQL);

        // Evict promoted keys from raw_data.
        // grade_detail_en → now in trim_en column
        // grade_detail_kr → duplicates lots.trim (= badge_detail_kr)
        DB::statement(<<<'SQL'
            UPDATE lots
            SET raw_data = JSON_REMOVE(
                JSON_REMOVE(raw_data, '$.grade_detail_en'),
                '$.grade_detail_kr'
            )
            WHERE JSON_EXTRACT(raw_data, '$.grade_detail_en') IS NOT NULL
               OR JSON_EXTRACT(raw_data, '$.grade_detail_kr') IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (Schema::hasColumn('lots', 'trim_en')) {
                $table->dropColumn('trim_en');
            }
        });
    }
};
