<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import catalog data from encar_inav_3levels.json
 * (produced by analysis/scrape_encar_inav_3levels.py).
 *
 * JSON format:
 *   {
 *     "manufacturers": [{"kr":"현대","en":"Hyundai","car_type":"Y","count":54393}],
 *     "model_groups":  [{"make_kr":"현대","make_en":"Hyundai","kr":"싼타페","en":"Santa Fe","count":6810}],
 *     "models":        [{"make_kr":"현대","mg_kr":"싼타페","kr":"싼타페 (MX5)","en":null,"count":492}]
 *   }
 *
 * Populates:
 *   catalog_models            — make_kr, make_en, model_group_kr, model_group_en, model_kr
 *   catalog_model_generations — auto-derived from model_kr regex \(([A-Z][A-Z0-9]{1,5})\)
 *   translations[make]        — KR → EN (official from Encar iNav)
 *   translations[model_group] — KR → EN (official from Encar iNav)
 *
 * Usage:
 *   php artisan catalog:import-from-inav ../analysis/encar_inav_3levels.json
 *   php artisan catalog:import-from-inav ../analysis/encar_inav_3levels.json --apply
 */
class CatalogImportFromInav extends Command
{
    protected $signature = 'catalog:import-from-inav
        {file : Path to encar_inav_3levels.json}
        {--apply : Persist changes (default: dry-run)}
        {--fresh : Truncate catalog_models before import}
        {--skip-models : Skip catalog_models}
        {--skip-translations : Skip translations table}';

    protected $description = 'Import Encar iNav 3-level catalog JSON into catalog_models and translations';

    // Matches chassis codes in parens: "(NQ5)", "(GN7)", "(DN8)", "(PD)"
    private const GEN_PAREN_PATTERN = '/^\(([A-Z][A-Z0-9]{1,5})\)$/';
    // Matches Korean generation suffix: "4세대", "더 뉴 4세대" etc.
    private const GEN_SEDAE_PATTERN = '/(\d+세대)/';

    public function handle(): int
    {
        $file  = (string) $this->argument('file');
        $apply = (bool) $this->option('apply');

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($file), true);
        if (! is_array($data)) {
            $this->error('Invalid JSON.');
            return self::FAILURE;
        }

        $this->info('catalog:import-from-inav');
        $this->line('File : ' . basename($file));
        $this->line('Mode : ' . ($apply ? '<fg=yellow>APPLY</>' : 'DRY-RUN'));
        if (! empty($data['meta'])) {
            $this->line(sprintf('Data : %s  |  scraped: %s',
                basename($file),
                $data['meta']['scraped_at'] ?? '?'
            ));
        }
        $this->line(sprintf(
            'Input: %d makes  %d model groups  %d models',
            count($data['manufacturers'] ?? []),
            count($data['model_groups'] ?? []),
            count($data['models'] ?? [])
        ));
        $this->line('');

        $stats = array_fill_keys([
            'models_new', 'models_upd', 'models_skip',
            'trans_new',  'trans_upd',  'trans_skip',
        ], 0);

        // ── 1. catalog_models ──────────────────────────────────────────────────
        if (! $this->option('skip-models')) {
            $this->line('<fg=cyan>── catalog_models ────────────────────────────────────</>');

            if ($this->option('fresh') && $apply) {
                $this->line('  <fg=red>TRUNCATE</>  catalog_models');
                DB::table('catalog_models')->truncate();
            }

            // Build make_en lookup from manufacturers list
            $makeEnMap = [];
            foreach ($data['manufacturers'] ?? [] as $m) {
                $makeEnMap[$m['kr']] = $m['en'] ?? '';
            }

            // Build model_group lookup: make_kr+mg_kr → {en, count}
            $mgMap = [];
            foreach ($data['model_groups'] ?? [] as $mg) {
                $mgMap[$mg['make_kr']][$mg['kr']] = ['en' => $mg['en'] ?? '', 'count' => $mg['count']];
            }

            // Pre-load existing models: "make_kr::model_kr" → row
            $existing = DB::table('catalog_models')
                ->get(['id', 'make_kr', 'model_kr', 'model_group_kr', 'model_group_en', 'generation'])
                ->keyBy(fn ($r) => "{$r->make_kr}::{$r->model_kr}")
                ->toArray();

            foreach ($data['models'] ?? [] as $row) {
                $makeKr   = $row['make_kr'];
                $mgKr     = $row['mg_kr'];
                $modelKr  = $row['kr'];
                $makeEn   = $makeEnMap[$makeKr] ?? '';
                $mgEn     = $mgMap[$makeKr][$mgKr]['en'] ?? '';
                $cacheKey = "{$makeKr}::{$modelKr}";

                $generation = $this->deriveGeneration($modelKr, $mgKr);

                if (! isset($existing[$cacheKey])) {
                    $stats['models_new']++;
                    $this->line(sprintf('  <fg=green>+MODEL</>  %s / %s / %s%s',
                        $makeEn ?: $makeKr,
                        $mgEn ?: $mgKr,
                        $modelKr,
                        $generation ? "  <fg=yellow>[gen={$generation}]</>" : ''
                    ));
                    if ($apply) {
                        DB::table('catalog_models')->insertOrIgnore([
                            'make_kr'        => $makeKr,
                            'make_en'        => $makeEn,
                            'model_group_kr' => $mgKr,
                            'model_group_en' => $mgEn,
                            'model_kr'       => $modelKr,
                            'generation'     => $generation,
                            'created_at'     => now(),
                            'updated_at'     => now(),
                        ]);
                        $newId = DB::table('catalog_models')
                            ->where('make_kr', $makeKr)->where('model_kr', $modelKr)
                            ->value('id');
                        $existing[$cacheKey] = (object) [
                            'id'             => $newId,
                            'make_kr'        => $makeKr,
                            'model_kr'       => $modelKr,
                            'model_group_kr' => $mgKr,
                            'model_group_en' => $mgEn,
                            'generation'     => $generation,
                        ];
                    }
                } else {
                    $rec = $existing[$cacheKey];
                    $updates = [];
                    if (empty($rec->model_group_kr) && $mgKr)     { $updates['model_group_kr'] = $mgKr; }
                    if (empty($rec->model_group_en) && $mgEn)     { $updates['model_group_en'] = $mgEn; }
                    if (empty($rec->generation)     && $generation){ $updates['generation']     = $generation; }

                    if ($updates) {
                        $stats['models_upd']++;
                        if ($apply) {
                            DB::table('catalog_models')
                                ->where('id', $rec->id)
                                ->update(array_merge($updates, ['updated_at' => now()]));
                        }
                    } else {
                        $stats['models_skip']++;
                    }
                }
            }

            $this->line(sprintf(
                '  → models: +%d new  %d updated  %d exist',
                $stats['models_new'], $stats['models_upd'], $stats['models_skip']
            ));
            $this->line('');
        }

        // ── 2. translations ────────────────────────────────────────────────────
        if (! $this->option('skip-translations')) {
            $this->line('<fg=cyan>── translations ──────────────────────────────────────</>');

            $translationGroups = [
                'make' => array_map(
                    fn ($m) => ['kr' => $m['kr'], 'en' => $m['en'] ?? null],
                    $data['manufacturers'] ?? []
                ),
                'model_group' => array_filter(
                    array_map(
                        fn ($mg) => ['kr' => $mg['kr'], 'en' => $mg['en'] ?? null],
                        $data['model_groups'] ?? []
                    ),
                    fn ($mg) => ! empty($mg['en']) && $mg['en'] !== $mg['kr']
                ),
            ];

            foreach ($translationGroups as $category => $pairs) {
                $existing = DB::table('translations')
                    ->where('category', $category)
                    ->pluck('en', 'kr')
                    ->toArray();

                $batch = [];
                foreach ($pairs as $pair) {
                    $kr = trim($pair['kr']);
                    $en = $pair['en'] ? trim($pair['en']) : null;
                    if ($kr === '') { continue; }

                    if (! isset($existing[$kr])) {
                        $stats['trans_new']++;
                        $this->line(sprintf('  <fg=green>+</>  [%s]  %s → %s',
                            $category, $kr, $en ?? '—'));
                        $batch[] = [
                            'category' => $category, 'kr' => $kr, 'en' => $en,
                            'ru' => null, 'source' => 'encar_inav',
                            'created_at' => now(), 'updated_at' => now(),
                        ];
                    } elseif ($en && $existing[$kr] !== $en) {
                        $stats['trans_upd']++;
                        $this->line(sprintf('  <fg=yellow>UPD</>  [%s]  %s: "%s" → "%s"',
                            $category, $kr, $existing[$kr] ?? '—', $en));
                        if ($apply) {
                            DB::table('translations')
                                ->where('category', $category)->where('kr', $kr)
                                ->update(['en' => $en, 'updated_at' => now()]);
                        }
                    } else {
                        $stats['trans_skip']++;
                    }
                }

                if ($apply && ! empty($batch)) {
                    foreach (array_chunk($batch, 200) as $chunk) {
                        DB::table('translations')->insertOrIgnore($chunk);
                    }
                }
            }

            $this->line(sprintf('  → +%d new  %d updated  %d exist',
                $stats['trans_new'], $stats['trans_upd'], $stats['trans_skip']));
            $this->line('');
        }

        // ── Summary ────────────────────────────────────────────────────────────
        $this->info('Done.');
        $this->table(['Table / Action', 'New', 'Updated', 'Exist'], [
            ['catalog_models', $stats['models_new'], $stats['models_upd'], $stats['models_skip']],
            ['translations',   $stats['trans_new'],  $stats['trans_upd'],  $stats['trans_skip']],
        ]);

        if (! $apply) {
            $this->line('');
            $this->comment('Dry-run — pass --apply to persist.');
        }

        return self::SUCCESS;
    }

    /**
     * Derive a generation string from model_kr + model_group_kr.
     *
     * Strategy (no arbitrary text parsing — uses known Encar patterns):
     *  1. Strip known model prefixes ("더 뉴", "올 뉴", etc.) from the start.
     *  2. Strip model_group_kr from the remaining string.
     *  3. What is left is the generation suffix. Strip parens and trim.
     *
     * Examples:
     *   ("카니발 4세대",         "카니발") → "4세대"
     *   ("더 뉴 카니발 4세대",   "카니발") → "4세대"
     *   ("그랜저 (GN7)",         "그랜저") → "GN7"
     *   ("쏘나타 디 엣지(DN8)",  "쏘나타") → "DN8"   (parens-only part)
     *   ("i30 (PD)",             "i30")    → "PD"
     *   ("스포티지 5세대 하이브리드", "스포티지") → "5세대 하이브리드"
     */
    private function deriveGeneration(string $modelKr, string $mgKr): ?string
    {
        // Strip known Korean model prefixes (ordered by length, longest first)
        $prefixes = [
            '더 뉴 더 뉴 ', '더뉴 더뉴 ', '더 뉴 더뉴 ',
            '완전변경 ', '부분변경 ',
            '더 뉴 ', '더뉴 ',
            '올 뉴 ', '올뉴 ',
            '신형 ', '뉴 ',
        ];
        $s = $modelKr;
        foreach ($prefixes as $p) {
            if (str_starts_with($s, $p)) {
                $s = substr($s, strlen($p));
                break;
            }
        }

        // Strip model_group_kr from the start of the remaining string
        if (str_starts_with($s, $mgKr)) {
            $s = substr($s, strlen($mgKr));
        }
        $s = trim($s);

        if ($s === '') {
            return null;
        }

        // If the entire remaining suffix is a paren code "(NQ5)", extract just the code
        if (preg_match(self::GEN_PAREN_PATTERN, $s, $m)) {
            return $m[1];
        }

        // Handle inline paren codes like "(DN8)" at the end: "디 엣지(DN8)" → "DN8"
        if (preg_match('/\(([A-Z][A-Z0-9]{1,5})\)\s*$/', $s, $m)) {
            // Return only the code, not the human-readable variant name
            return $m[1];
        }

        // "4세대", "5세대 하이브리드", etc. — return as-is
        return $s;
    }
}
