<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fill missing tech spec fields in lots using catalog_badges lookup.
 *
 * No regex. No string parsing. Pure SQL JOIN.
 *
 * Fills (only if NULL):
 *   lots.engine_volume  ← catalog_badges.engine_volume
 *   lots.drive_type     ← catalog_badges.drive_type
 *   lots.fuel           ← catalog_badges.fuel  (fallback)
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

    protected $description = 'Fill missing lots fields from catalog_badges (no regex)';

    public function handle(): int
    {
        $apply  = (bool) $this->option('apply');
        $source = (string) $this->option('source');

        $this->info('lots:normalize-from-catalog  mode=' . ($apply ? 'APPLY' : 'DRY-RUN'));

        $updates = [
            'engine_volume' => 'cb.engine_volume',
            'drive_type'    => 'cb.drive_type',
            'fuel'          => 'cb.fuel',
        ];

        foreach ($updates as $field => $src) {
            $count = (int) DB::selectOne("
                SELECT COUNT(*) AS cnt
                FROM lots l
                JOIN catalog_badges cb ON l.badge = cb.badge_kr
                WHERE l.source = ?
                  AND l.{$field} IS NULL
                  AND {$src} IS NOT NULL
            ", [$source])->cnt;

            $this->line("  {$field}: {$count} lots to fill");

            if ($apply && $count > 0) {
                DB::statement("
                    UPDATE lots l
                    JOIN catalog_badges cb ON l.badge = cb.badge_kr
                    SET l.{$field} = {$src},
                        l.updated_at = NOW()
                    WHERE l.source = ?
                      AND l.{$field} IS NULL
                      AND {$src} IS NOT NULL
                ", [$source]);
            }
        }

        $this->line('');
        $this->info('Done.');
        if (! $apply) {
            $this->comment('Dry-run — pass --apply to persist.');
        }

        return self::SUCCESS;
    }
}
