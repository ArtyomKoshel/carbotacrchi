<?php

namespace App\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * Where lots stand in the reference catalog, for the API: one query for a page of lots.
 * Only what the platform stated — a modification's fuel / drive are null unless its badge said them.
 */
class CatalogPlaces
{
    /**
     * @param  list<string> $lotIds
     * @return array<string, array{make: ?string, model: ?string, generation: ?string, year_from: ?int, year_to: ?int, modification: ?string,
     *                              fuel: ?string, drive: ?string, complectation: ?string, editions: list<string>, status: string}>
     */
    public function of(array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }
        $rows = DB::table('lots as l')
            ->join('ref_variants as v', 'v.id', '=', 'l.ref_variant_id')
            ->leftJoin('ref_makes as k', 'k.id', '=', 'v.make_id')
            ->leftJoin('ref_models as m', 'm.id', '=', 'v.model_id')
            ->leftJoin('ref_generations as g', 'g.id', '=', 'v.generation_id')
            ->leftJoin('ref_modifications as d', 'd.id', '=', 'v.modification_id')
            ->leftJoin('ref_complectations as c', 'c.id', '=', 'v.complectation_id')
            ->whereIn('l.id', $lotIds)->whereNotNull('v.model_id')
            ->get(['l.id', 'k.name as make', 'm.name as model', 'g.code as generation', 'g.year_from', 'g.year_to', 'd.code as modification',
                'd.fuel', 'd.drive', 'c.name as complectation', 'v.editions', 'v.status']);

        $out = [];
        foreach ($rows as $r) {
            $place = (array) $r;
            unset($place['id']);
            $place['editions'] = $place['editions'] ? json_decode($place['editions'], true) : [];
            $out[(string) $r->id] = $place;
        }
        return $out;
    }

    /**
     * Adds `catalog` to API lot arrays (null when a lot is not on the catalog).
     *
     * @param  list<array<string, mixed>> $lots
     * @return list<array<string, mixed>>
     */
    public function attach(array $lots): array
    {
        $places = $this->of(array_values(array_filter(array_map(fn ($l) => (string) ($l['id'] ?? ''), $lots))));
        return array_map(fn (array $l) => [...$l, 'catalog' => $places[(string) ($l['id'] ?? '')] ?? null], $lots);
    }
}
