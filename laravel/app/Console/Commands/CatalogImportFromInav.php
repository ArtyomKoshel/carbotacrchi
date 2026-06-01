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
        {--fresh : Truncate catalog_models + catalog_model_generations before import}
        {--skip-models : Skip catalog_models + generations}
        {--skip-translations : Skip translations table}';

    protected $description = 'Import Encar iNav 3-level catalog JSON into catalog_models and translations';

    private const GEN_PATTERN = '/\(([A-Z][A-Z0-9]{1,5})\)/';

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
            'gens_new',   'gens_skip',
            'trans_new',  'trans_upd',  'trans_skip',
        ], 0);

        // ── 1. catalog_models ──────────────────────────────────────────────────
        if (! $this->option('skip-models')) {
            $this->line('<fg=cyan>── catalog_models ────────────────────────────────────</>');

            if ($this->option('fresh') && $apply) {
                $this->line('  <fg=red>TRUNCATE</>  catalog_model_generations, catalog_models');
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                DB::table('catalog_model_generations')->truncate();
                DB::table('catalog_models')->truncate();
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
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

            // Pre-load existing models: "make_kr::model_kr" → id
            $existing = DB::table('catalog_models')
                ->get(['id', 'make_kr', 'model_kr', 'model_group_kr', 'model_group_en'])
                ->keyBy(fn ($r) => "{$r->make_kr}::{$r->model_kr}")
                ->toArray();

            // Pre-load generation codes: model_id → [code, ...]
            $existingGens = [];
            DB::table('catalog_model_generations')
                ->get(['model_id', 'code'])
                ->each(fn ($r) => $existingGens[(int) $r->model_id][] = strtoupper($r->code));

            foreach ($data['models'] ?? [] as $row) {
                $makeKr   = $row['make_kr'];
                $mgKr     = $row['mg_kr'];
                $modelKr  = $row['kr'];
                $makeEn   = $makeEnMap[$makeKr] ?? '';
                $mgEn     = $mgMap[$makeKr][$mgKr]['en'] ?? '';
                $cacheKey = "{$makeKr}::{$modelKr}";

                if (! isset($existing[$cacheKey])) {
                    // INSERT new model
                    $stats['models_new']++;
                    $this->line(sprintf('  <fg=green>+MODEL</>  %s / %s [%s] / %s',
                        $makeEn ?: $makeKr, $mgEn ?: $mgKr, $mgKr, $modelKr));
                    if ($apply) {
                        DB::table('catalog_models')->insertOrIgnore([
                            'make_kr'        => $makeKr,
                            'make_en'        => $makeEn,
                            'model_group_kr' => $mgKr,
                            'model_group_en' => $mgEn,
                            'model_kr'       => $modelKr,
                            'created_at'     => now(),
                            'updated_at'     => now(),
                        ]);
                        $newId = DB::table('catalog_models')
                            ->where('make_kr', $makeKr)->where('model_kr', $modelKr)
                            ->value('id');
                        $existing[$cacheKey] = (object) [
                            'id' => $newId, 'make_kr' => $makeKr, 'model_kr' => $modelKr,
                            'model_group_kr' => $mgKr, 'model_group_en' => $mgEn,
                        ];
                    }
                } else {
                    $rec = $existing[$cacheKey];
                    // Fill missing model_group_kr/en if blank
                    $needsUpdate = ($apply && (
                        (empty($rec->model_group_kr) && $mgKr) ||
                        (empty($rec->model_group_en) && $mgEn)
                    ));
                    if ($needsUpdate) {
                        $stats['models_upd']++;
                        DB::table('catalog_models')->where('id', $rec->id)->update([
                            'model_group_kr' => $mgKr ?: $rec->model_group_kr,
                            'model_group_en' => $mgEn ?: $rec->model_group_en,
                            'updated_at'     => now(),
                        ]);
                    } else {
                        $stats['models_skip']++;
                    }
                }

                // Auto-derive generation codes from model string, e.g. "싼타페 (MX5)" → MX5
                $modelId = $existing[$cacheKey]->id ?? null;
                if ($modelId && preg_match_all(self::GEN_PATTERN, $modelKr, $m)) {
                    $known = array_map('strtoupper', $existingGens[$modelId] ?? []);
                    foreach ($m[1] as $code) {
                        $code = strtoupper($code);
                        if (in_array($code, $known, true)) { $stats['gens_skip']++; continue; }
                        $stats['gens_new']++;
                        $this->line("    <fg=green>+GEN</>   {$modelKr} → {$code}");
                        if ($apply) {
                            DB::table('catalog_model_generations')->insertOrIgnore([
                                'model_id'   => $modelId,
                                'code'       => $code,
                                'created_at' => now(), 'updated_at' => now(),
                            ]);
                            $existingGens[$modelId][] = $code;
                        }
                    }
                }
            }

            $this->line(sprintf(
                '  → models: +%d new  %d updated  %d exist  |  generations: +%d new  %d exist',
                $stats['models_new'], $stats['models_upd'], $stats['models_skip'],
                $stats['gens_new'],   $stats['gens_skip']
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
            ['catalog_models',            $stats['models_new'], $stats['models_upd'], $stats['models_skip']],
            ['catalog_model_generations', $stats['gens_new'],   0,                   $stats['gens_skip']],
            ['translations',              $stats['trans_new'],  $stats['trans_upd'],  $stats['trans_skip']],
        ]);

        if (! $apply) {
            $this->line('');
            $this->comment('Dry-run — pass --apply to persist.');
        }

        return self::SUCCESS;
    }
}
