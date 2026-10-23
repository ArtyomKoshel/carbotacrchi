<?php

namespace App\Console\Commands;

use App\Catalog\CatalogLinker;
use App\Catalog\Encar\EncarMatcher;
use App\Services\ProviderAggregator;
use App\Services\SearchQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Proves the catalog filters lose no car (read-only):
 *   1. every active lot of a catalog platform is linked to a variant;
 *   2. every active lot of a make the catalog has is on that make;
 *   3. per make, the cells of /api/catalog/{make} add up to its active lots, and the search
 *      the miniapp sends for each cell (model, generation, modification, complectation —
 *      "not stated" included) finds exactly that many;
 *   4. with --tree: our active lots per Encar model against Encar's own counts.
 * Exit code 1 when 1–3 fail.
 */
class CatalogReconcile extends Command
{
    protected $signature = 'catalog:reconcile
        {--make= : One make (English) for check 3}
        {--sample=0 : Cells per make for check 3 (0 = all)}
        {--tree= : An Encar tree (analysis/scrape_encar_inav_tree.py) to compare model counts with}';

    protected $description = 'Check that the catalog filters lose no lot (read-only)';

    public function handle(ProviderAggregator $search, EncarMatcher $matcher): int
    {
        $failed = false;

        $unlinked = DB::table('lots')->where('is_active', true)->whereIn('source', CatalogLinker::SOURCES)->whereNull('ref_variant_id')->count();
        $this->line(($unlinked ? '<error>FAIL</error>' : '<info>OK</info>') . "  1. active lots not linked to a variant: {$unlinked}");
        $failed = $failed || $unlinked > 0;

        $korean = DB::table('ref_makes')->pluck('name')->mapWithKeys(fn ($m) => [$matcher->koreanMake($m) ?? $m => $m])->all();
        $offMake = DB::table('lots')->where('is_active', true)->whereIn('make', array_keys($korean))->whereNull('ref_make_id')
            ->groupBy('make')->selectRaw('make, COUNT(*) n')->pluck('n', 'make');
        $this->line(($offMake->sum() ? '<error>FAIL</error>' : '<info>OK</info>') . '  2. active lots of a catalog make that are not on it: ' . $offMake->sum());
        $failed = $failed || $offMake->sum() > 0;

        $failed = $this->cells($search) || $failed;

        if ($tree = $this->option('tree')) {
            $this->encar((string) $tree);
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Check 3; true when it fails. */
    private function cells(ProviderAggregator $search): bool
    {
        $makes = DB::table('ref_makes')->when($this->option('make'), fn ($q, $m) => $q->where('name', $m))->get(['id', 'name']);
        $sample = max(0, (int) $this->option('sample'));
        $rows = [];
        $failed = false;
        foreach ($makes as $make) {
            $active = DB::table('lots')->where('is_active', true)->where('ref_make_id', $make->id)->count();
            if ($active === 0) {
                continue;
            }
            // the very query of /api/catalog/{make}
            $cells = DB::table('lots')->where('is_active', true)->where('ref_make_id', $make->id)
                ->groupBy('ref_model_id', 'ref_generation_id', 'ref_modification_id', 'ref_complectation_id')
                ->selectRaw('ref_model_id m, ref_generation_id g, ref_modification_id d, ref_complectation_id c, COUNT(*) n')->get();
            $sum = (int) $cells->sum('n');
            $checked = $wrong = 0;
            foreach ($sample ? $cells->shuffle()->take($sample) : $cells as $c) {
                // what the miniapp sends for this cell: null → the "not stated" choice
                $q = SearchQuery::fromArray([
                    'refMakeId' => $make->id,
                    'refModelId' => $c->m ?? -1,
                    'refGenerationIds' => $c->m === null ? [] : [(int) ($c->g ?? 0)],
                    'refModificationIds' => $c->m === null ? [] : [(int) ($c->d ?? 0)],
                    'refComplectationIds' => [(int) ($c->c ?? 0)],
                    'sources' => CatalogLinker::SOURCES,
                ]);
                $found = $search->count($q);
                $checked++;
                if ($found !== (int) $c->n) {
                    $wrong++;
                    $this->warn("   {$make->name} cell m={$c->m} g={$c->g} d={$c->d} c={$c->c}: {$c->n} lots, the search finds {$found}");
                }
            }
            $ok = $sum === $active && $wrong === 0;
            $failed = $failed || ! $ok;
            $rows[] = [$make->name, $active, $sum, $cells->count(), $checked, $wrong, $ok ? 'OK' : 'FAIL'];
        }
        $this->line('   3. per make: active lots = the cells of its tree; each cell found by the search exactly');
        $this->table(['Make', 'Active lots', 'Σ cells', 'Cells', 'Searched', 'Wrong', ''], $rows);
        return $failed;
    }

    /** Check 4: our active lots per Encar model against the counts in an Encar tree. */
    private function encar(string $path): void
    {
        $tree = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $encar = [];
        foreach ($tree['taxonomy'] as $r) {
            $encar["{$r['make_kr']}|{$r['model_kr']}"] ??= (int) ($r['model_count'] ?? 0);
        }
        $ours = DB::table('lots')->where('is_active', true)->where('source', 'encar')
            ->groupBy('make', 'model')->selectRaw('make, model, COUNT(*) n')->get()
            ->mapWithKeys(fn ($r) => ["{$r->make}|{$r->model}" => (int) $r->n])->all();

        $rows = [];
        $total = ['encar' => array_sum($encar), 'ours' => array_sum($ours)];
        foreach ($encar + $ours as $key => $_) {
            $e = $encar[$key] ?? 0;
            $o = $ours[$key] ?? 0;
            if (abs($e - $o) > max(5, 0.1 * max($e, $o))) {
                $rows[] = [...explode('|', $key, 2), $e, $o, $o - $e];
            }
        }
        usort($rows, fn ($a, $b) => abs($b[4]) <=> abs($a[4]));
        $this->line(sprintf('   4. Encar (%s): %d listings; ours: %d active; models off by more than 10%% (and 5): %d',
            $tree['meta']['scraped_at'] ?? '?', $total['encar'], $total['ours'], count($rows)));
        $this->table(['Make', 'Encar model', 'Encar', 'Ours', 'Δ'], array_slice($rows, 0, 40));
    }
}
