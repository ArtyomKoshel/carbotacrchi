<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fill / update English fields in lots from catalog tables.
 *
 * Pure SQL JOINs — no regex, no string parsing, no static dictionaries.
 * All mappings live in catalog_models, catalog_badges, and translations.
 *
 * Step 0  (auto): translate:run — AI fills missing translations
 * Step 1: make_en         ← translations.make
 * Step 2: model_group_en  ← catalog_models.model_group_en + translations.model_group
 * Step 2b: model_en       ← translations.model
 * Step 3: badge_group_en  ← translations.badge_group
 * Step 4:  drive_type + seat_count ← catalog_badges (exact badge match)
 * Step 4b: drive_type             ← catalog_drive_tokens (badge_en token scan)
 * Step 5: trim_en         ← translations.trim
 * Step 6: seat_color      ← translations.seat_color
 * Step 7: color           ← translations.color
 *
 * fuel/engine_volume come from the Encar detail API (spec.fuelName, spec.displacement).
 * badge_group/badge are display fields — fuel/engine_volume not extracted from them.
 *
 * Usage:
 *   php artisan lots:normalize-from-catalog --apply
 *   php artisan lots:normalize-from-catalog --apply --force
 *   php artisan lots:normalize-from-catalog --apply --skip-translate
 */
class LotsNormalizeFromCatalog extends Command
{
    protected $signature = 'lots:normalize-from-catalog
        {--apply            : Persist updates (default: dry-run)}
        {--force            : Overwrite make_en / model_group_en / model_en even when already set}
        {--skip-translate   : Skip the AI translate:run step (use when AI API unavailable)}
        {--source=encar     : Source to process}';

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
                          AND tmk.en       = l.make_en
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
                    OR cm.make_en = l.make_en
                    OR EXISTS (
                        SELECT 1 FROM translations tmk
                        WHERE tmk.category = 'make'
                          AND tmk.kr       = cm.make_kr
                          AND tmk.en       = l.make_en
                    )
                )
        ";
    }

    public function handle(): int
    {
        $apply         = (bool) $this->option('apply');
        $force         = (bool) $this->option('force');
        $source        = (string) $this->option('source');
        $skipTranslate = (bool) $this->option('skip-translate');

        $mode = $apply ? ($force ? 'APPLY + FORCE' : 'APPLY') : 'DRY-RUN';
        $this->info("lots:normalize-from-catalog  source={$source}  mode={$mode}");
        $this->line('');

        // ── 0. AI translations (translate:run) ────────────────────────────────
        // Fills translations table (KR→EN cache) before SQL normalization steps.
        // Skipped in dry-run mode or when --skip-translate is passed.
        if ($apply && ! $skipTranslate && config('ai.api_key')) {
            $this->line('<fg=cyan>0. AI translations</> (translate:run — fills translations cache)');

            $translateCategories = 'make,model,model_group,badge_group,badge,trim';

            $this->line("   running: translate:run --category={$translateCategories} --source={$source} --apply");

            Artisan::call('translate:run', [
                '--category' => $translateCategories,
                '--source'   => $source,
                '--apply'    => true,
                '--limit'    => 300,
                '--batch'    => 20,
            ]);

            // Show output from the sub-command
            $output = Artisan::output();
            if ($output) {
                foreach (explode("\n", trim($output)) as $line) {
                    if ($line !== '') {
                        $this->line("   " . $line);
                    }
                }
            }

            $this->line('');
        } elseif (! $apply) {
            $this->line('<fg=gray>0. AI translations</> (skipped in dry-run)');
            $this->line('');
        } elseif ($skipTranslate) {
            $this->line('<fg=gray>0. AI translations</> (skipped via --skip-translate)');
            $this->line('');
        } elseif (! config('ai.api_key')) {
            $this->warn('0. AI translations: skipped (AI_API_KEY not set)');
            $this->line('');
        }

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

        // ── 2. model_group_en — from catalog_models.model_group_en ──────────────
        $this->line('<fg=cyan>2. model_group_en</> (catalog_models.model_group_en)');

        $mgEnNull = "(l.model_group_en IS NULL OR l.model_group_en = '')";
        if ($force) {
            $mgEnNull = '1=1';
        }

        $modelGroupJoin = $this->modelGroupJoin();
        $mgEnCount      = (int) DB::selectOne("
            SELECT COUNT(DISTINCT l.id) AS cnt
            FROM lots l
            {$modelGroupJoin}
            WHERE l.source          = ?
              AND {$mgEnNull}
              AND cm.model_group_en IS NOT NULL
              AND cm.model_group_en != ''
        ", [$source])->cnt;

        $this->line("   lots to fill: {$mgEnCount}");

        if ($apply && $mgEnCount > 0) {
            DB::statement("
                UPDATE lots l
                {$modelGroupJoin}
                SET l.model_group_en = cm.model_group_en,
                    l.updated_at     = NOW()
                WHERE l.source          = ?
                  AND {$mgEnNull}
                  AND cm.model_group_en IS NOT NULL
                  AND cm.model_group_en != ''
            ", [$source]);
        }

        $this->line('');

        // ── 2b. model_en — from translations.model ───────────────────────────
        $this->line('<fg=cyan>2b. model_en</> (translations.model)');

        $modelEnNull = "(l.model_en IS NULL OR l.model_en = '')";
        if ($force) {
            $modelEnNull = '1=1';
        }

        $modelEnCount = (int) DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM lots l
            JOIN translations tm ON tm.category = 'model' AND tm.kr = l.model
            WHERE l.source = ? AND {$modelEnNull} AND tm.en IS NOT NULL
        ", [$source])->cnt;

        $this->line("   lots to fill: {$modelEnCount}");

        if ($apply && $modelEnCount > 0) {
            DB::statement("
                UPDATE lots l
                JOIN translations tm ON tm.category = 'model' AND tm.kr = l.model
                SET l.model_en   = tm.en,
                    l.updated_at = NOW()
                WHERE l.source = ? AND {$modelEnNull} AND tm.en IS NOT NULL
            ", [$source]);
        }

        $this->line('');

        // ── 3. badge_group_en + badge_en — from translations ─────────────────
        $this->line('<fg=cyan>3. badge_group_en</> (translations.badge_group)');

        $bgEnNull = "(l.badge_group_en IS NULL OR l.badge_group_en = '')";
        if ($force) {
            $bgEnNull = '1=1';
        }

        $bgEnCount = (int) DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM lots l
            JOIN translations tb ON tb.category = 'badge_group' AND tb.kr = l.badge_group
            WHERE l.source = ? AND {$bgEnNull} AND tb.en IS NOT NULL
        ", [$source])->cnt;

        $this->line("   lots to fill: {$bgEnCount}");

        if ($apply && $bgEnCount > 0) {
            DB::statement("
                UPDATE lots l
                JOIN translations tb ON tb.category = 'badge_group' AND tb.kr = l.badge_group
                SET l.badge_group_en = tb.en,
                    l.updated_at     = NOW()
                WHERE l.source = ? AND {$bgEnNull} AND tb.en IS NOT NULL
            ", [$source]);
        }

        // badge_en
        $this->line('<fg=cyan>3b. badge_en</> (translations.badge)');

        $badgeEnNull = "(l.badge_en IS NULL OR l.badge_en = '')";
        if ($force) {
            $badgeEnNull = '1=1';
        }

        $badgeEnCount = (int) DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM lots l
            JOIN translations tb ON tb.category = 'badge' AND tb.kr = l.badge
            WHERE l.source = ? AND {$badgeEnNull} AND tb.en IS NOT NULL
        ", [$source])->cnt;

        $this->line("   lots to fill: {$badgeEnCount}");

        if ($apply && $badgeEnCount > 0) {
            DB::statement("
                UPDATE lots l
                JOIN translations tb ON tb.category = 'badge' AND tb.kr = l.badge
                SET l.badge_en   = tb.en,
                    l.updated_at = NOW()
                WHERE l.source = ? AND {$badgeEnNull} AND tb.en IS NOT NULL
            ", [$source]);
        }

        $this->line('');

        // ── 4. drive_type + seat_count from catalog_badges ───────────────────
        // fuel/engine_volume come from the Encar detail API (spec.fuelName, spec.displacement)
        // badge_group is a display/translation field only — no regex extraction needed
        $this->line('<fg=cyan>4. drive_type + seat_count</> (catalog_badges fallback for detail-API misses)');

        $badgeJoin = $this->badgeJoin();

        foreach (['drive_type' => 'cb.drive_type', 'seat_count' => 'cb.seat_count'] as $field => $src) {
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

        // ── 4b. drive_type from catalog_drive_tokens (badge_en token scan) ──────
        // Tokens: xDrive, 4MATIC, quattro, sDrive, 2WD, FWD, etc.
        // Runs AFTER catalog_badges so exact match wins over token match.
        $this->line('<fg=cyan>4b. drive_type</> (catalog_drive_tokens badge_en token scan)');

        $tokenCount = (int) DB::selectOne("
            SELECT COUNT(DISTINCT l.id) AS cnt
            FROM lots l
            JOIN catalog_drive_tokens cdt
                ON l.badge_en LIKE CONCAT('%', cdt.token, '%')
            WHERE l.source     = ?
              AND l.drive_type IS NULL
              AND l.badge_en   IS NOT NULL
        ", [$source])->cnt;

        $this->line("   drive_type from tokens: {$tokenCount} lots");

        if ($apply && $tokenCount > 0) {
            // Apply one token at a time in drive_type priority order: awd > rwd > fwd
            foreach (['awd', 'rwd', 'fwd'] as $dt) {
                DB::statement("
                    UPDATE lots l
                    JOIN catalog_drive_tokens cdt ON cdt.drive_type = ?
                    SET l.drive_type = ?,
                        l.updated_at = NOW()
                    WHERE l.source     = ?
                      AND l.drive_type IS NULL
                      AND l.badge_en   IS NOT NULL
                      AND l.badge_en LIKE CONCAT('%', cdt.token, '%')
                ", [$dt, $dt, $source]);
            }
        }

        $this->line('');

        // ── 5. trim — clean Encar placeholder only (raw BadgeDetail from parser, no catalog fill)
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
        $this->line('<fg=cyan>5. trim_en</> (translations.trim)');

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

        // ── 6. seat_color — translate Korean → English via translations ───────
        $this->line('<fg=cyan>6. seat_color</> (translations.seat_color)');

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

        // ── 7. color — fix remaining Korean color values ──────────────────────
        $this->line('<fg=cyan>7. color</> (translations.color — fix Korean remnants)');

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
