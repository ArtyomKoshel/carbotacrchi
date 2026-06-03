<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add lots.make_en — canonical English brand name.
 *
 * lots.make now stores raw Korean as-is from the Encar API ("기아", "현대").
 * lots.make_en is populated by lots:normalize-from-catalog via translations.make.
 *
 * Backfill strategy:
 *   1. Korean make  → JOIN translations WHERE kr = make → make_en = translations.en
 *   2. English make → copy directly (existing lots written before this change)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (! Schema::hasColumn('lots', 'make_en')) {
                $table->string('make_en', 100)->nullable()->after('make');
                $table->index('make_en');
            }
        });

        // Backfill: Korean make → translate via translations.make
        DB::statement(<<<'SQL'
            UPDATE lots l
            JOIN translations t ON t.category = 'make' AND t.kr = l.make
            SET l.make_en = t.en
            WHERE l.make_en IS NULL
        SQL);

        // Backfill: already-English make (no Korean chars) → copy as-is
        DB::statement(<<<'SQL'
            UPDATE lots
            SET make_en = make
            WHERE make_en IS NULL
              AND make NOT REGEXP '[가-힣]'
              AND make != ''
        SQL);
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (Schema::hasColumn('lots', 'make_en')) {
                $table->dropIndex(['make_en']);
                $table->dropColumn('make_en');
            }
        });
    }
};
