<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Populate catalog_trims from real lot data.
 *
 * Sources (in priority order):
 *   1. lots.trim (KR) + lots.trim_en (EN) — Encar official, highest quality
 *   2. lots.trim (KR) only — for makes/trims where detail API was never called
 *
 * The command also syncs translations table (category='trim') from the same pairs.
 *
 * Usage:
 *   php artisan catalog:populate-trims            # dry-run
 *   php artisan catalog:populate-trims --apply    # persist
 */
class CatalogPopulateTrims extends Command
{
    protected $signature = 'catalog:populate-trims
        {--apply : Persist changes (default: dry-run)}
        {--min-count=2 : Minimum lot count for a trim to be included (noise filter)}
        {--source=encar : Source to read from}';

    protected $description = 'Populate catalog_trims and translations[trim] from real lot data';

    public function handle(): int
    {
        $apply    = (bool) $this->option('apply');
        $minCount = max(1, (int) $this->option('min-count'));
        $source   = (string) $this->option('source');

        $this->info('catalog:populate-trims  mode=' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line("source={$source}  min_count={$minCount}");
        $this->line('');

        // ── 1. Collect distinct (make, trim_kr, trim_en) pairs from lots ──────────
        $rows = DB::table('lots')
            ->where('source', $source)
            ->whereNotNull('trim')
            ->where('trim', '!=', '')
            ->where('trim', '!=', '(세부등급 없음)')
            ->selectRaw('
                make,
                trim                            AS trim_kr,
                trim_en,
                COUNT(*)                        AS cnt,
                SUM(trim_en IS NOT NULL AND trim_en != \'\') AS cnt_with_en
            ')
            ->groupBy('make', 'trim', 'trim_en')
            ->having('cnt', '>=', $minCount)
            ->orderBy('make')
            ->orderByDesc('cnt')
            ->get();

        // Collapse: for the same (make, trim_kr) keep the most common trim_en
        $byMakeTrim = [];   // [make][trim_kr] → ['trim_en' => ..., 'cnt' => ...]
        foreach ($rows as $r) {
            $key = $r->trim_kr;
            $existing = $byMakeTrim[$r->make][$key] ?? null;
            if ($existing === null || $r->cnt_with_en > ($existing['cnt_with_en'] ?? 0)) {
                $byMakeTrim[$r->make][$key] = [
                    'trim_en'      => ($r->trim_en && $r->trim_en !== '') ? $r->trim_en : null,
                    'cnt'          => (int) $r->cnt,
                    'cnt_with_en'  => (int) $r->cnt_with_en,
                ];
            }
        }

        // Also build make-agnostic pairs (trim_kr that appear with identical EN across makes)
        $agnostic = [];  // trim_kr → ['trim_en' => ..., 'makes' => [...]]
        foreach ($byMakeTrim as $make => $trims) {
            foreach ($trims as $trimKr => $data) {
                if ($data['trim_en'] === null) {
                    continue;
                }
                if (!isset($agnostic[$trimKr])) {
                    $agnostic[$trimKr] = ['trim_en' => $data['trim_en'], 'makes' => [$make], 'cnt' => $data['cnt']];
                } elseif ($agnostic[$trimKr]['trim_en'] === $data['trim_en']) {
                    $agnostic[$trimKr]['makes'][] = $make;
                    $agnostic[$trimKr]['cnt'] += $data['cnt'];
                } else {
                    // Conflict: same KR trim maps to different EN across makes → keep make-specific only
                    $agnostic[$trimKr]['conflict'] = true;
                }
            }
        }

        $statCatalogNew    = 0;
        $statCatalogUpdate = 0;
        $statTransNew      = 0;

        $this->line('<fg=cyan>────────── catalog_trims ──────────</>');

        foreach ($byMakeTrim as $make => $trims) {
            foreach ($trims as $trimKr => $data) {
                $trimEn = $data['trim_en'];
                $isUniversal = !isset($agnostic[$trimKr]['conflict'])
                    && isset($agnostic[$trimKr])
                    && $agnostic[$trimKr]['trim_en'] === $trimEn
                    && count($agnostic[$trimKr]['makes']) > 1;

                // Determine make_en for catalog row ('': universal, else canonical make)
                $catalogMakeEn = $isUniversal ? '' : $make;

                $existing = DB::table('catalog_trims')
                    ->where('trim_kr', $trimKr)
                    ->where('make_en', $catalogMakeEn)
                    ->first();

                if ($existing === null) {
                    $statCatalogNew++;
                    $this->line(sprintf(
                        '  <fg=green>NEW</>   [%s] %s → %s',
                        $catalogMakeEn ?: '*',
                        $trimKr,
                        $trimEn ?? '—'
                    ));
                    if ($apply) {
                        DB::table('catalog_trims')->insert([
                            'trim_kr'    => $trimKr,
                            'trim_en'    => $trimEn,
                            'make_en'    => $catalogMakeEn,
                            'priority'   => $isUniversal ? 80 : 90,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                } elseif ($trimEn !== null && $existing->trim_en !== $trimEn) {
                    $statCatalogUpdate++;
                    $this->line(sprintf(
                        '  <fg=yellow>UPD</>   [%s] %s: "%s" → "%s"',
                        $catalogMakeEn ?: '*',
                        $trimKr,
                        $existing->trim_en ?? '—',
                        $trimEn
                    ));
                    if ($apply) {
                        DB::table('catalog_trims')
                            ->where('trim_kr', $trimKr)
                            ->where('make_en', $catalogMakeEn)
                            ->update(['trim_en' => $trimEn, 'updated_at' => now()]);
                    }
                }
            }
        }

        // ── 2. Sync translations table (category='trim') ─────────────────────────
        $this->line('');
        $this->line('<fg=cyan>────────── translations[trim] ──────────</>');

        // Only universal (make-agnostic) pairs go to translations — those are global lookups
        foreach ($agnostic as $trimKr => $data) {
            if (isset($data['conflict']) || $data['trim_en'] === null) {
                continue;
            }
            $existing = DB::table('translations')
                ->where('category', 'trim')
                ->where('kr', $trimKr)
                ->first();

            if ($existing === null) {
                $statTransNew++;
                $this->line(sprintf('  <fg=green>NEW</>  %s → %s  (%dx)', $trimKr, $data['trim_en'], $data['cnt']));
                if ($apply) {
                    DB::table('translations')->insert([
                        'category'   => 'trim',
                        'kr'         => $trimKr,
                        'en'         => $data['trim_en'],
                        'source'     => 'encar_official',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            } elseif ($existing->en !== $data['trim_en'] && $existing->source === 'encar_official') {
                // Update only if source is still encar_official (respect manual overrides)
                $this->line(sprintf('  <fg=yellow>UPD</>  %s: "%s" → "%s"', $trimKr, $existing->en, $data['trim_en']));
                if ($apply) {
                    DB::table('translations')
                        ->where('category', 'trim')
                        ->where('kr', $trimKr)
                        ->update(['en' => $data['trim_en'], 'updated_at' => now()]);
                }
            }
        }

        $this->line('');
        $this->info('Done.');
        $this->line("catalog_trims:   +{$statCatalogNew} new  {$statCatalogUpdate} updated");
        $this->line("translations:    +{$statTransNew} new");

        if (! $apply) {
            $this->line('');
            $this->comment('Dry-run — pass --apply to persist.');
        }

        return self::SUCCESS;
    }
}
