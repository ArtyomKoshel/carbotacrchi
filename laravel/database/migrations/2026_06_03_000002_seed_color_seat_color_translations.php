<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed translations for color and seat_color categories.
 *
 * color      — Encar exterior color values not covered by the parser COLOR_MAP
 * seat_color — Encar interior color strings (always Korean "X색 계열" format)
 *
 * After seeding, lots:normalize-from-catalog translates:
 *   lots.color      WHERE NOT regexp ^[A-Za-z]  → translations.color
 *   lots.seat_color (always Korean)              → translations.seat_color
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [];

        // ── seat_color (전체 목록) ────────────────────────────────────────────
        $seatColors = [
            '검정색 계열'  => 'Black',
            '갈색 계열'    => 'Brown',
            '베이지색 계열' => 'Beige',
            '회색 계열'    => 'Gray',
            '빨간색 계열'  => 'Red',
            '청색 계열'    => 'Blue',
            '녹색 계열'    => 'Green',
            '흰색 계열'    => 'White',
            '주황색 계열'  => 'Orange',
            '노란색 계열'  => 'Yellow',
            '보라색 계열'  => 'Purple',
        ];

        foreach ($seatColors as $kr => $en) {
            $rows[] = [
                'category'   => 'seat_color',
                'kr'         => $kr,
                'en'         => $en,
                'ru'         => null,
                'source'     => 'manual',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // ── color (값이 없어서 Korean으로 남는 것들) ─────────────────────────
        $colors = [
            '은회색' => 'Silver',
            '명은색' => 'Silver',
            '연금색' => 'Gold',
            '연두색' => 'Green',
        ];

        foreach ($colors as $kr => $en) {
            $rows[] = [
                'category'   => 'color',
                'kr'         => $kr,
                'en'         => $en,
                'ru'         => null,
                'source'     => 'manual',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('translations')->insertOrIgnore($chunk);
        }

        // Backfill lots.color where Korean color value now has a translation
        DB::statement(<<<'SQL'
            UPDATE lots l
            JOIN translations t ON t.category = 'color' AND t.kr = l.color
            SET l.color = t.en
            WHERE l.color IS NOT NULL
              AND l.color REGEXP '[가-힣]'
        SQL);

        // Backfill lots.seat_color — always Korean, translate to English
        DB::statement(<<<'SQL'
            UPDATE lots l
            JOIN translations t ON t.category = 'seat_color' AND t.kr = l.seat_color
            SET l.seat_color = t.en
            WHERE l.seat_color IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::table('translations')
            ->whereIn('category', ['seat_color', 'color'])
            ->where('source', 'manual')
            ->delete();
    }
};
