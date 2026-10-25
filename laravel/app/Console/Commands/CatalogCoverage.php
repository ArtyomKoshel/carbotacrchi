<?php

namespace App\Console\Commands;

use App\Catalog\CatalogLinker;
use App\Catalog\Encar\EncarMatcher;
use App\Catalog\VariantMatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * How much of a make's Encar variants the reference catalog understands. Reads only:
 * the distinct Encar variants (make, model, badge group, badge, trim) of active lots, or the same rows from a JSON
 * export (--input), and prints coverage by variants and by lots, the variants it could
 * not read, and with --tree the resulting model → generation → modification → complectation tree.
 */
class CatalogCoverage extends Command
{
    protected $signature = 'catalog:coverage
        {--make=BMW : The reference make}
        {--input= : JSON rows {model|model_en, badge, trim, n, years|year, fuel} instead of the database}
        {--tree : Print the tree the variants make}
        {--dump= : Write every variant with its match to this JSON file}
        {--limit=40 : Unread variants to list}';

    protected $description = 'Coverage of Encar variants by the reference catalog (read-only)';

    public function handle(EncarMatcher $matcher): int
    {
        $make = (string) $this->option('make');
        $korean = $matcher->koreanMake($make);
        if ($korean === null) {
            $this->error("{$make}: not in the catalog (resources/catalog/encar)");
            return self::FAILURE;
        }
        $rows = $this->option('input') ? $this->fromFile((string) $this->option('input'), $korean) : $this->fromDb($korean);

        $totals = [];
        $results = [];
        foreach ($rows as $row) {
            $match = $matcher->match($row);
            $results[] = ['row' => $row, 'match' => $match];
            $totals[$match->status]['variants'] = ($totals[$match->status]['variants'] ?? 0) + 1;
            $totals[$match->status]['lots'] = ($totals[$match->status]['lots'] ?? 0) + $row['n'];
        }

        $allVariants = count($results);
        $allLots = array_sum(array_column($rows, 'n'));
        $this->info("{$make}: {$allVariants} variants, {$allLots} lots");
        $this->table(['Status', 'Variants', '%', 'Lots', '%'], array_map(fn (string $s) => [
            $s,
            $totals[$s]['variants'] ?? 0, self::pct($totals[$s]['variants'] ?? 0, $allVariants),
            $totals[$s]['lots'] ?? 0, self::pct($totals[$s]['lots'] ?? 0, $allLots),
        ], [VariantMatch::MATCHED, VariantMatch::NO_ENGINE, VariantMatch::UNMATCHED]));

        $this->listStatus($results, VariantMatch::UNMATCHED, 'Not read');
        $this->listStatus($results, VariantMatch::NO_ENGINE, 'No engine in the badge');
        $this->fuelConflicts($results);

        if ($this->option('tree')) {
            $this->tree($results);
        }
        if ($dump = $this->option('dump')) {
            file_put_contents($dump, json_encode(array_map(fn ($r) => $r['row'] + ['match' => $r['match']->toArray()], $results),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("Written: {$dump}");
        }
        return self::SUCCESS;
    }

    private function listStatus(array $results, string $status, string $title): void
    {
        $hits = array_values(array_filter($results, fn ($r) => $r['match']->status === $status));
        if (!$hits) {
            return;
        }
        usort($hits, fn ($a, $b) => $b['row']['n'] <=> $a['row']['n']);
        $this->newLine();
        $this->line("<comment>{$title}</comment>");
        $this->table(['Model', 'Badge', 'Trim', 'Lots', 'Left / reason'], array_map(fn ($r) => [
            $r['row']['model'], $r['row']['badge'], $r['row']['trim'] === $r['row']['badge'] ? '' : $r['row']['trim'], $r['row']['n'],
            $r['match']->leftover ? implode(' | ', $r['match']->leftover) : (string) $r['match']->reason,
        ], array_slice($hits, 0, (int) $this->option('limit'))));
    }

    /** Lots whose own fuel (from the platform) is not the modification's. */
    private function fuelConflicts(array $results): void
    {
        $out = [];
        foreach ($results as $r) {
            $own = array_filter(explode(',', (string) ($r['row']['fuel'] ?? '')));
            $ours = $r['match']->fuel;
            if ($ours && $own && array_diff($own, [$ours])) {
                $out[] = [$r['row']['model'], $r['row']['badge'], $r['match']->modification, $ours, implode(',', $own), $r['row']['n']];
            }
        }
        if ($out) {
            $this->newLine();
            $this->line('<comment>Fuel differs from the platform</comment>');
            $this->table(['Model', 'Badge', 'Modification', 'Ours', 'Platform', 'Lots'], $out);
        }
    }

    private function tree(array $results): void
    {
        $tree = [];
        foreach ($results as $r) {
            $m = $r['match'];
            if ($m->status === VariantMatch::UNMATCHED) {
                continue;
            }
            $gen = "{$m->model} ({$m->generation})";
            $mod = ($m->modification ?? '—') . " · {$m->fuel} · {$m->drive}";
            $equip = trim(($m->complectation ?? 'base') . ($m->editions ? ' · ' . implode(', ', $m->editions) : '')
                . ($m->body ? " · {$m->body}" : '') . ($m->seats ? " · {$m->seats} seats" : ''));
            $tree[$gen][$mod][$equip] = ($tree[$gen][$mod][$equip] ?? 0) + $r['row']['n'];
        }
        ksort($tree, SORT_NATURAL);
        $this->newLine();
        foreach ($tree as $gen => $mods) {
            $this->line("<info>{$gen}</info>");
            ksort($mods, SORT_NATURAL);
            foreach ($mods as $mod => $equips) {
                $this->line("  {$mod}");
                arsort($equips);
                foreach ($equips as $equip => $n) {
                    $this->line("      {$equip}  ({$n})");
                }
            }
        }
    }

    /**
     * Distinct Encar variants of the make's active lots, by Encar's own (Korean) fields.
     *
     * @return list<array{make: string, model: ?string, badge_group: ?string, badge: ?string, trim: ?string, n: int, year: ?int, fuel: ?string}>
     */
    private function fromDb(string $korean): array
    {
        return DB::table('lots as l')->where('l.is_active', true)->where('l.source', 'encar')->where('l.make', $korean)
            ->groupByRaw('l.make, ' . CatalogLinker::MODEL_SQL . ', l.badge_group, l.badge, l.`trim`')
            ->selectRaw('l.make, ' . CatalogLinker::MODEL_SQL . ' model, l.badge_group, l.badge, l.`trim`, COUNT(*) n, MIN(l.year) year, GROUP_CONCAT(DISTINCT l.fuel) fuel')
            ->get()
            ->map(fn ($r) => ['make' => $r->make, 'model' => $r->model, 'badge_group' => $r->badge_group, 'badge' => $r->badge,
                'trim' => $r->trim, 'n' => (int) $r->n, 'year' => $r->year === null ? null : (int) $r->year, 'fuel' => $r->fuel])
            ->all();
    }

    /** The same rows from a JSON export (Korean make and model in make / model, or kmake / kmodel). */
    private function fromFile(string $path, string $korean): array
    {
        return array_map(fn (array $r) => [
            'make' => $r['kmake'] ?? $r['make'] ?? $korean,
            'model' => $r['kmodel'] ?? $r['model'] ?? null,
            'badge_group' => $r['badge_group'] ?? $r['bg'] ?? null,
            'badge' => $r['badge'] ?? null,
            'trim' => $r['trim'] ?? null,
            'n' => (int) ($r['n'] ?? 1),
            'year' => isset($r['year']) ? (int) $r['year'] : (isset($r['years']) ? (int) $r['years'] : null),
            'fuel' => $r['fuel'] ?? null,
        ], json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
    }

    private static function pct(int $part, int $all): string
    {
        return $all ? number_format(100 * $part / $all, 1) . '%' : '—';
    }
}
