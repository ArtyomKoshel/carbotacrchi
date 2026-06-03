<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fill / update English fields in lots from catalog tables.
 *
 * Pure SQL JOINs — no regex, no string parsing, no static dictionaries.
 * All mappings live in catalog_models, catalog_badges, and translations.
 *
 * Hierarchy:
 *   lots.make (Korean raw) → translations.make          → lots.make_en
 *   lots.make + model_group → catalog_models            → lots.model_en, lots.generation
 *   lots.make + model_group + badge → catalog_badges    → lots.fuel, drive_type, engine_volume, seat_count, trim
 *   lots.trim  (Korean)    → translations.trim          → lots.trim_en
 *   lots.seat_color (Korean) → translations.seat_color  → lots.seat_color (overwrite with EN)
 *   lots.color  (Korean)   → translations.color         → lots.color (overwrite with EN)
 *
 * make matching works for BOTH:
 *   - New lots: lots.make = "기아" (Korean raw from parser)
 *   - Old lots: lots.make = "Kia" (English from old parser vocab)
 *
 * Usage:
 *   php artisan lots:normalize-from-catalog            # dry-run
 *   php artisan lots:normalize-from-catalog --apply    # run updates
 *   php artisan lots:normalize-from-catalog --apply --force  # overwrite model_en/make_en
 */
class LotsNormalizeFromCatalog extends Command
{
    protected $signature = 'lots:normalize-from-catalog
        {--apply  : Persist updates (default: dry-run)}
        {--force  : Overwrite make_en / model_en even when already set}
        {--source=encar : Source to process}';

    protected $description = 'Fill English lot fields from catalog tables (no regex, catalog-only)';

    /**
     * JOIN catalog_badges matching both Korean makes (new lots) and English makes (legacy).
     *
     * New parser stores lots.make = "기아" (Korean) → cb.make_kr = "기아" matches directly.
     * Old lots store lots.make = "Kia" (English)   → translations.en = l.make fallback.
     */
    private function badgeJoin(): string
    {
        return "
            JOIN catalog_badges cb
                ON  cb.model_group_kr = l.model_group
                AND cb.badge_kr       = l.badge
                AND (
                    cb.make_kr = l.make
                    OR EXISTS (
                        SELECT 1 FROM translations tmk
                        WHERE tmk.category = 'make'
                          AND tmk.kr       = cb.make_kr
                          AND tmk.en       = l.make
                    )
                )
        ";
    }

    /**
     * JOIN catalog_models matching both Korean and English make.
     * model_group match only (used for model_en — one per model group).
     */
    private function modelGroupJoin(): string
    {
        return "
            JOIN catalog_models cm
                ON  cm.model_group_kr = l.model_group
                AND (
                    cm.make_kr = l.make
                    OR cm.make_en = l.make
                    OR EXISTS (
                        SELECT 1 FROM translations tmk
                        WHERE tmk.category = 'make'
                          AND tmk.kr       = cm.make_kr
                          AND tmk.en       = l.make
                    )
                )
        ";
    }

    /**
     * JOIN catalog_models with exact model_kr match (used for generation).
     */
    private function modelExactJoin(): string
    {
        return "
            JOIN catalog_models cm
                ON  cm.model_group_kr = l.model_group
                AND cm.model_kr       = l.model
                AND (
                    cm.make_kr = l.make
                    OR cm.make_en = l.make
                    OR EXISTS (
                        SELECT 1 FROM translations tmk
                        WHERE tmk.category = 'make'
                          AND tmk.kr       = cm.make_kr
                          AND tmk.en       = l.make
                    )
                )
        ";
    }

    public function handle(): int
    {
        $apply  = (bool) $this->option('apply');
        $force  = (bool) $this->option('force');
        $source = (string) $this->option('source');

        $mode = $apply ? ($force ? 'APPLY + FORCE' : 'APPLY') : 'DRY-RUN';
        $this->info("lots:normalize-from-catalog  source={$source}  mode={$mode}");
        $this->line('');

        // ── 1. make_en — translate Korean make via translations.make ──────────
        $this->line('<fg=cyan>1. make_en</> (translations.make)');

        $makeEnNull = "(l.make_en IS NULL OR l.make_en = '')";
        if ($force) {
            $makeEnNull = '1=1';
        }

        $makeEnCount = (int) DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM lots l
            JOIN translations tmk ON tmk.category = 'make' AND tmk.kr = l.make
            WHERE l.source = ? AND {$makeEnNull}
              AND tmk.en IS NOT NULL
        ", [$source])->cnt;

        $this->line("   Korean make → translate: {$makeEnCount} lots");

        if ($apply && $makeEnCount > 0) {
            DB::statement("
                UPDATE lots l
                JOIN translations tmk ON tmk.category = 'make' AND tmk.kr = l.make
                SET l.make_en = tmk.en, l.updated_at = NOW()
                WHERE l.source = ? AND {$makeEnNull} AND tmk.en IS NOT NULL
            ", [$source]);
        }

        // Fallback: if make is already English (old lots), copy directly
        $makeEnCopyCount = (int) DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM lots
            WHERE source = ?
              AND (make_en IS NULL OR make_en = '')
              AND make NOT REGEXP '[가-힣]'
              AND make != ''
        ", [$source])->cnt;

        $this->line("   English make → copy: {$makeEnCopyCount} lots");

        if ($apply && $makeEnCopyCount > 0) {
            DB::statement("
                UPDATE lots
                SET make_en = make, updated_at = NOW()
                WHERE source = ?
                  AND (make_en IS NULL OR make_en = '')
                  AND make NOT REGEXP '[가-힣]'
                  AND make != ''
            ", [$source]);
        }

        $this->line('');

        // ── 2. model_en — from catalog_models.model_group_en ─────────────────
        $this->line('<fg=cyan>2. model_en</> (catalog_models.model_group_en)');

        $modelEnNull = "(l.model_en IS NULL OR l.model_en = '')";
        if ($force) {
            $modelEnNull = '1=1';
        }

        $modelGroupJoin = $this->modelGroupJoin();
        $modelEnCount   = (int) DB::selectOne("
            SELECT COUNT(DISTINCT l.id) AS cnt
            FROM lots l
            {$modelGroupJoin}
            WHERE l.source          = ?
              AND {$modelEnNull}
              AND cm.model_group_en IS NOT NULL
              AND cm.model_group_en != ''
        ", [$source])->cnt;

        $this->line("   lots to fill: {$modelEnCount}");

        if ($apply && $modelEnCount > 0) {
            DB::statement("
                UPDATE lots l
                {$modelGroupJoin}
                SET l.model_en   = cm.model_group_en,
                    l.updated_at = NOW()
                WHERE l.source          = ?
                  AND {$modelEnNull}
                  AND cm.model_group_en IS NOT NULL
                  AND cm.model_group_en != ''
            ", [$source]);
        }

        $this->line('');

        // ── 3. generation — from catalog_models.generation ───────────────────
        $this->line('<fg=cyan>3. generation</> (catalog_models.generation)');

        $modelExactJoin = $this->modelExactJoin();
        $genCount       = (int) DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM lots l
            {$modelExactJoin}
            WHERE l.source     = ?
              AND l.generation IS NULL
              AND cm.generation IS NOT NULL
        ", [$source])->cnt;

        $this->line("   lots to fill: {$genCount}");

        if ($apply && $genCount > 0) {
            DB::statement("
                UPDATE lots l
                {$modelExactJoin}
                SET l.generation = cm.generation,
                    l.updated_at = NOW()
                WHERE l.source     = ?
                  AND l.generation IS NULL
                  AND cm.generation IS NOT NULL
            ", [$source]);
        }

        $this->line('');

        // ── 4. Tech specs from catalog_badges ────────────────────────────────
        $this->line('<fg=cyan>4. tech specs</> (catalog_badges: fuel, drive_type, engine_volume, seat_count)');

        $badgeJoin = $this->badgeJoin();

        foreach (['fuel' => 'cb.fuel', 'engine_volume' => 'cb.engine_volume', 'drive_type' => 'cb.drive_type', 'seat_count' => 'cb.seat_count'] as $field => $src) {
            $count = (int) DB::selectOne("
                SELECT COUNT(*) AS cnt
                FROM lots l
                {$badgeJoin}
                WHERE l.source   = ?
                  AND l.{$field} IS NULL
                  AND {$src}     IS NOT NULL
            ", [$source])->cnt;

            $this->line("   {$field}: {$count} lots");

            if ($apply && $count > 0) {
                DB::statement("
                    UPDATE lots l
                    {$badgeJoin}
                    SET l.{$field}   = {$src},
                        l.updated_at = NOW()
                    WHERE l.source   = ?
                      AND l.{$field} IS NULL
                      AND {$src}     IS NOT NULL
                ", [$source]);
            }
        }

        $this->line('');

        // ── 5. trim (Korean) from catalog_badges.trim_kr ─────────────────────
        $this->line('<fg=cyan>5. trim</> (catalog_badges.trim_kr)');

        $trimCount = (int) DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM lots l
            {$badgeJoin}
            WHERE l.source    = ?
              AND l.trim      IS NULL
              AND cb.trim_kr  IS NOT NULL
        ", [$source])->cnt;

        $this->line("   trim NULL → fill from badge: {$trimCount} lots");

        if ($apply && $trimCount > 0) {
            DB::statement("
                UPDATE lots l
                {$badgeJoin}
                SET l.trim       = cb.trim_kr,
                    l.updated_at = NOW()
                WHERE l.source   = ?
                  AND l.trim     IS NULL
                  AND cb.trim_kr IS NOT NULL
            ", [$source]);
        }

        // Clean Encar placeholder
        $placeholderCount = (int) DB::selectOne("
            SELECT COUNT(*) AS cnt FROM lots
            WHERE source = ? AND trim = '(세부등급 없음)'
        ", [$source])->cnt;

        $this->line("   trim placeholder → NULL: {$placeholderCount} lots");

        if ($apply && $placeholderCount > 0) {
            DB::statement("
                UPDATE lots SET trim = NULL, updated_at = NOW()
                WHERE source = ? AND trim = '(세부등급 없음)'
            ", [$source]);
        }

        $this->line('');

        // ── 6. trim_en — translate Korean trim via translations.trim ─────────
        $this->line('<fg=cyan>6. trim_en</> (translations.trim)');

        $trimEnCount = (int) DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM lots l
            JOIN translations tt ON tt.category = 'trim' AND tt.kr = l.trim
            WHERE l.source = ?
              AND l.trim     IS NOT NULL
              AND (l.trim_en IS NULL OR l.trim_en = '')
              AND tt.en IS NOT NULL
        ", [$source])->cnt;

        $this->line("   lots to fill: {$trimEnCount}");

        if ($apply && $trimEnCount > 0) {
            DB::statement("
                UPDATE lots l
                JOIN translations tt ON tt.category = 'trim' AND tt.kr = l.trim
                SET l.trim_en    = tt.en,
                    l.updated_at = NOW()
                WHERE l.source = ?
                  AND l.trim     IS NOT NULL
                  AND (l.trim_en IS NULL OR l.trim_en = '')
                  AND tt.en IS NOT NULL
            ", [$source]);
        }

        $this->line('');

        // ── 7. seat_color — translate Korean → English via translations ───────
        $this->line('<fg=cyan>7. seat_color</> (translations.seat_color)');

        $seatColorCount = (int) DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM lots l
            JOIN translations ts ON ts.category = 'seat_color' AND ts.kr = l.seat_color
            WHERE l.source = ?
              AND l.seat_color IS NOT NULL
              AND ts.en IS NOT NULL
        ", [$source])->cnt;

        $this->line("   Korean seat_color → EN: {$seatColorCount} lots");

        if ($apply && $seatColorCount > 0) {
            DB::statement("
                UPDATE lots l
                JOIN translations ts ON ts.category = 'seat_color' AND ts.kr = l.seat_color
                SET l.seat_color  = ts.en,
                    l.updated_at  = NOW()
                WHERE l.source = ?
                  AND l.seat_color IS NOT NULL
                  AND ts.en IS NOT NULL
            ", [$source]);
        }

        $this->line('');

        // ── 8. color — fix remaining Korean color values ──────────────────────
        $this->line('<fg=cyan>8. color</> (translations.color — fix Korean remnants)');

        $colorCount = (int) DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM lots l
            JOIN translations tc ON tc.category = 'color' AND tc.kr = l.color
            WHERE l.source = ?
              AND l.color IS NOT NULL
              AND tc.en IS NOT NULL
        ", [$source])->cnt;

        $this->line("   Korean color → EN: {$colorCount} lots");

        if ($apply && $colorCount > 0) {
            DB::statement("
                UPDATE lots l
                JOIN translations tc ON tc.category = 'color' AND tc.kr = l.color
                SET l.color      = tc.en,
                    l.updated_at = NOW()
                WHERE l.source = ?
                  AND l.color IS NOT NULL
                  AND tc.en IS NOT NULL
            ", [$source]);
        }

        $this->line('');
        $this->info('Done.');

        if (! $apply) {
            $this->comment('Dry-run — pass --apply to persist.');
        } else {
            $this->invalidateFilterCache();
            $this->line('  ✓ Filter cache invalidated');
        }

        return self::SUCCESS;
    }

    private function invalidateFilterCache(): void
    {
        $keys = Cache::get('api_filters_cache_keys', []);
        if (! empty($keys)) {
            foreach ($keys as $key) {
                Cache::forget($key);
            }
            return;
        }
        foreach (['ru', 'en'] as $locale) {
            foreach (['encar', 'kbcha', 'encar_kbcha'] as $src) {
                Cache::forget("api_filters_{$locale}_{$src}");
            }
        }
    }
}
