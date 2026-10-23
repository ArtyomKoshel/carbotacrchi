<?php

namespace App\Console\Commands;

use App\Catalog\CatalogLinker;
use App\Catalog\CatalogStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Puts every lot on the reference catalog after the reference or the rules changed:
 * writes the reference into ref_* (nothing deleted), reads every variant again (those set
 * by hand stay), then points lots at their variants in chunks. Safe while the parser runs:
 * a chunk is one UPDATE of up to --chunk lots by primary key.
 */
class CatalogRemap extends Command
{
    protected $signature = 'catalog:remap
        {--chunk=2000 : Lots per UPDATE}
        {--pause-ms=50 : Pause between chunks}
        {--new-only : Read only variants not read yet (the rules did not change)}';

    protected $description = 'Write the reference catalog and place every lot on it';

    public function handle(CatalogStore $store, CatalogLinker $linker): int
    {
        $started = microtime(true);
        $counts = $store->sync();
        $this->info(sprintf('Reference: %d makes, %d models, %d generations, %d complectations',
            $counts['makes'], $counts['models'], $counts['generations'], $counts['complectations']));

        $variants = DB::table('lots')->whereIn('source', CatalogLinker::SOURCES)
            ->groupBy('source', ...CatalogLinker::VARIANT_FIELDS)
            ->select('source', ...CatalogLinker::VARIANT_FIELDS)->selectRaw('SUM(is_active) AS active')
            ->get();
        $active = [];
        foreach ($variants as $v) {
            $active[CatalogLinker::hash($v)] = (int) $v->active;
        }
        $stored = $linker->store($variants, again: ! $this->option('new-only'), lots: $active);
        $this->info(sprintf('Variants: %d in lots, %d read, %d placed differently than before (%.1f s)',
            count($variants), $stored['read'], $stored['changed'], microtime(true) - $started));

        $chunk = max(100, (int) $this->option('chunk'));
        $pause = max(0, (int) $this->option('pause-ms')) * 1000;
        $last = '';
        $written = $seen = 0;
        do {
            $ids = DB::table('lots')->whereIn('source', CatalogLinker::SOURCES)->where('id', '>', $last)
                ->orderBy('id')->limit($chunk)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            $written += $linker->apply($ids);
            $seen += count($ids);
            $last = end($ids);
            if ($pause) {
                usleep($pause);
            }
        } while (count($ids) === $chunk);
        $this->info(sprintf('Lots: %d seen, %d changed (%.1f s)', $seen, $written, microtime(true) - $started));

        $this->summary();
        return self::SUCCESS;
    }

    /** Active lots per make and status, the variants left for review first. */
    private function summary(): void
    {
        $rows = DB::table('ref_variants as v')->leftJoin('ref_makes as m', 'm.id', '=', 'v.make_id')
            ->groupBy('m.name', 'v.status')
            ->selectRaw("COALESCE(m.name, '—') make, v.status, COUNT(*) variants, SUM(v.lots) lots")
            ->orderByRaw('make')->get();
        $table = [];
        foreach ($rows as $r) {
            $table[$r->make] ??= [$r->make, 0, 0, 0, 0];
            $col = ['matched' => 1, 'no_engine' => 2, 'unmatched' => 3][$r->status] ?? 3;
            $table[$r->make][$col] += (int) $r->lots;
            $table[$r->make][4] += (int) $r->lots;
        }
        $this->table(['Make', 'Matched', 'No engine', 'Unmatched', 'Active lots'], array_values($table));
    }
}
