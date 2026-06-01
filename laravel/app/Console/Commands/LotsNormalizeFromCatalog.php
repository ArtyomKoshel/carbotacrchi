<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fill missing tech spec fields in lots using catalog_badges lookup.
 *
 * No regex. No string parsing. Pure SQL JOIN on (make, model_group, badge).
 *
 * The composite key (make_kr, model_group_kr, badge_kr) is required because
 * the same badge string (e.g. "9인승 시그니처") can appear for multiple models
 * with different specs. A global badge_kr lookup would return wrong data.
 *
 * Fills (only if NULL):
 *   lots.fuel           ← catalog_badges.fuel
 *   lots.engine_volume  ← catalog_badges.engine_volume
 *   lots.drive_type     ← catalog_badges.drive_type
 *   lots.seat_count     ← catalog_badges.seat_count
 *   lots.trim           ← catalog_badges.trim_kr  (only if lots.trim IS NULL)
 *
 * make matching: lots.make (English) is matched to catalog_badges.make_kr (Korean)
 * via the translations table.
 *
 * Usage:
 *   php artisan lots:normalize-from-catalog            # show counts
 *   php artisan lots:normalize-from-catalog --apply    # run updates
 */
class LotsNormalizeFromCatalog extends Command
{
    protected $signature = 'lots:normalize-from-catalog
        {--apply : Persist updates (default: dry-run)}
        {--source=encar : Source to process}';

    protected $description = 'Fill missing lots fields from catalog_badges using composite key (make, model_group, badge)';

    public function handle(): int
    {
        $apply  = (bool) $this->option('apply');
        $source = (string) $this->option('source');

        $this->info('lots:normalize-from-catalog  mode=' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line('');

        // Fields filled from catalog_badges columns
        // lots column → catalog_badges column
        $specFields = [
            'fuel'          => 'cb.fuel',
            'engine_volume' => 'cb.engine_volume',
            'drive_type'    => 'cb.drive_type',
            'seat_count'    => 'cb.seat_count',
        ];

        // JOIN condition:
        //   lots.model_group = catalog_badges.model_group_kr  (Korean, direct)
        //   lots.badge       = catalog_badges.badge_kr        (Korean, direct)
        //   lots.make        = translations.en (where translations.kr = cb.make_kr AND category='make')
        //
        // This avoids storing a Korean make column in lots while still matching correctly.
        $joinSql = "
            JOIN catalog_badges cb
                ON  l.model_group = cb.model_group_kr
                AND l.badge       = cb.badge_kr
                AND EXISTS (
                    SELECT 1 FROM translations t
                    WHERE t.category = 'make'
                      AND t.kr       = cb.make_kr
                      AND t.en       = l.make
                )
        ";

        foreach ($specFields as $field => $src) {
            $count = (int) DB::selectOne("
                SELECT COUNT(*) AS cnt
                FROM lots l
                {$joinSql}
                WHERE l.source     = ?
                  AND l.{$field}  IS NULL
                  AND {$src}      IS NOT NULL
            ", [$source])->cnt;

            $this->line("  {$field}: {$count} lots to fill");

            if ($apply && $count > 0) {
                DB::statement("
                    UPDATE lots l
                    {$joinSql}
                    SET l.{$field}    = {$src},
                        l.updated_at  = NOW()
                    WHERE l.source    = ?
                      AND l.{$field} IS NULL
                      AND {$src}     IS NOT NULL
                ", [$source]);
            }
        }

        // trim filled separately — only when lots.trim IS NULL AND catalog has trim_kr
        $trimCount = (int) DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM lots l
            {$joinSql}
            WHERE l.source    = ?
              AND l.trim      IS NULL
              AND cb.trim_kr  IS NOT NULL
        ", [$source])->cnt;

        $this->line("  trim: {$trimCount} lots to fill from catalog_badges.trim_kr");

        if ($apply && $trimCount > 0) {
            DB::statement("
                UPDATE lots l
                {$joinSql}
                SET l.trim       = cb.trim_kr,
                    l.updated_at = NOW()
                WHERE l.source   = ?
                  AND l.trim     IS NULL
                  AND cb.trim_kr IS NOT NULL
            ", [$source]);
        }

        // Fill lots.generation from catalog_models lookup
        $genCount = (int) DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM lots l
            JOIN catalog_models cm
                ON  l.model_group = cm.model_group_kr
                AND l.model       = cm.model_kr
                AND EXISTS (
                    SELECT 1 FROM translations t
                    WHERE t.category = 'make'
                      AND t.kr       = cm.make_kr
                      AND t.en       = l.make
                )
            WHERE l.source      = ?
              AND l.generation  IS NULL
              AND cm.generation IS NOT NULL
        ", [$source])->cnt;

        $this->line("  generation: {$genCount} lots to fill from catalog_models.generation");

        if ($apply && $genCount > 0) {
            DB::statement("
                UPDATE lots l
                JOIN catalog_models cm
                    ON  l.model_group = cm.model_group_kr
                    AND l.model       = cm.model_kr
                    AND EXISTS (
                        SELECT 1 FROM translations t
                        WHERE t.category = 'make'
                          AND t.kr       = cm.make_kr
                          AND t.en       = l.make
                    )
                SET l.generation  = cm.generation,
                    l.updated_at  = NOW()
                WHERE l.source      = ?
                  AND l.generation  IS NULL
                  AND cm.generation IS NOT NULL
            ", [$source]);
        }

        $this->line('');
        $this->info('Done.');

        if (! $apply) {
            $this->comment('Dry-run — pass --apply to persist.');
        }

        return self::SUCCESS;
    }
}
