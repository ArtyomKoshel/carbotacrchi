<?php

namespace App\Catalog;

use App\Catalog\Encar\EncarMatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Points lots at the reference catalog. A lot's variant is its platform fields (make, model,
 * badge group, badge, trim); each variant is read by the rules once and kept in
 * ref_variants, so linking a lot is a hash lookup, and `catalog:remap` reads ~20k variants
 * instead of 200k lots. A variant set by hand (manual) is never read again.
 *
 * The same hash is computed in PHP (hash()) and in MySQL (SQL_HASH), so lots join their
 * variant without a column of their own.
 */
class CatalogLinker
{
    /** Platforms the catalog reads; other lots stay unlinked. */
    public const SOURCES = ['encar'];

    public const VARIANT_FIELDS = ['make', 'model', 'badge_group', 'badge', 'trim'];

    public const SQL_HASH = "SHA1(CONCAT_WS(CHAR(31), IFNULL(l.make, ''), IFNULL(l.model, ''), IFNULL(l.badge_group, ''), "
        . "IFNULL(l.badge, ''), IFNULL(l.`trim`, '')))";

    private readonly ConnectionInterface $db;

    public function __construct(
        private readonly EncarMatcher $matcher,
        private readonly CatalogStore $store,
        ?ConnectionInterface $db = null,
    ) {
        $this->db = $db ?? DB::connection();
    }

    public static function hash(array|object $variant): string
    {
        $v = (array) $variant;
        return sha1(implode("\x1f", array_map(fn (string $f) => (string) ($v[$f] ?? ''), self::VARIANT_FIELDS)));
    }

    /**
     * Link these lots: variants nobody has read yet are read, then the lots take their ids.
     *
     * @param  list<string> $lotIds
     * @return int lots whose catalog place changed
     */
    public function link(array $lotIds): int
    {
        if ($lotIds === []) {
            return 0;
        }
        $this->store($this->variants(fn ($q) => $q->whereIn('l.id', $lotIds)));
        return $this->apply($lotIds);
    }

    /**
     * Distinct variants of the lots $where picks, with their active lots. Grouped by the hash,
     * not by the columns: the tables compare case-insensitively, and "2.0 LPI" / "2.0 LPi" are
     * two variants of Encar's (two hashes), which a GROUP BY on the columns would fold into one.
     *
     * @param  callable(\Illuminate\Database\Query\Builder): mixed $where
     * @return list<object> rows with source, VARIANT_FIELDS, active
     */
    public function variants(callable $where): array
    {
        $fields = implode(', ', array_map(fn ($f) => "ANY_VALUE(l.`{$f}`) AS `{$f}`", self::VARIANT_FIELDS));
        $query = $this->db->table('lots as l')->whereIn('l.source', self::SOURCES)
            ->selectRaw('l.source, ' . self::SQL_HASH . " AS h, {$fields}, SUM(l.is_active) AS active")
            ->groupByRaw('l.source, h');
        $where($query);
        return $query->get()->all();
    }

    /**
     * Reads and stores the variants not in ref_variants yet; with $again also the stored ones
     * that were not set by hand. $lots: active lots per hash, for the review queue's order.
     *
     * @param  iterable<object|array> $variants rows with source and VARIANT_FIELDS
     * @param  array<string, int> $lots
     * @return array{read: int, changed: int}
     */
    public function store(iterable $variants, bool $again = false, array $lots = []): array
    {
        $bySource = [];
        foreach ($variants as $v) {
            $v = (array) $v;
            $bySource[$v['source']][self::hash($v)] = $v;
        }

        $read = $changed = 0;
        $now = now()->toDateTimeString();
        $this->store->preload();
        foreach ($bySource as $source => $rows) {
            foreach (array_chunk($rows, 500, true) as $chunk) {
                $stored = $this->db->table('ref_variants')->where('source', $source)->whereIn('hash', array_keys($chunk))
                    ->get(['id', 'hash', 'manual', 'status', 'make_id', 'model_id', 'generation_id', 'modification_id', 'complectation_id'])
                    ->keyBy('hash');
                // one upsert per chunk: the read rows whole, the kept ones (by hand, or not read again) only their lot count
                $full = $counts = [];
                foreach ($chunk as $hash => $v) {
                    $old = $stored->get($hash);
                    if ($old !== null && ($old->manual || ! $again)) {
                        if (isset($lots[$hash])) {
                            $counts[] = ['source' => $source, 'hash' => $hash, 'status' => $old->status, 'lots' => $lots[$hash], 'updated_at' => $now];
                        }
                        continue;
                    }
                    $row = $this->read($source, $v);
                    $read++;
                    if ($old !== null && self::differs($old, $row)) {
                        $changed++;
                    }
                    $full[] = [...$row, 'source' => $source, 'hash' => $hash, 'lots' => $lots[$hash] ?? 0, 'created_at' => $now, 'updated_at' => $now];
                }
                if ($full) {
                    $this->db->table('ref_variants')->upsert($full, ['source', 'hash'],
                        array_values(array_diff(array_keys($full[0]), ['source', 'hash', 'created_at'])));
                }
                if ($counts) {
                    $this->db->table('ref_variants')->upsert($counts, ['source', 'hash'], ['lots', 'updated_at']);
                }
            }
        }
        return ['read' => $read, 'changed' => $changed];
    }

    /**
     * Lots take their variant's ids (only rows where something differs are written).
     *
     * @param  list<string> $lotIds
     */
    public function apply(array $lotIds): int
    {
        if ($lotIds === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($lotIds), '?'));
        return $this->db->update(
            'UPDATE lots l JOIN ref_variants v ON v.source = l.source AND v.hash = ' . self::SQL_HASH . '
             SET l.ref_variant_id = v.id, l.ref_make_id = v.make_id, l.ref_model_id = v.model_id,
                 l.ref_generation_id = v.generation_id, l.ref_modification_id = v.modification_id,
                 l.ref_complectation_id = v.complectation_id
             WHERE l.id IN (' . $placeholders . ')
               AND NOT (l.ref_variant_id <=> v.id AND l.ref_make_id <=> v.make_id AND l.ref_model_id <=> v.model_id
                    AND l.ref_generation_id <=> v.generation_id AND l.ref_modification_id <=> v.modification_id
                    AND l.ref_complectation_id <=> v.complectation_id)',
            $lotIds,
        );
    }

    /**
     * Places a variant by hand (the admin's review queue): it is never read again, and its
     * lots move at once. A modification is named by its code; fuel / drive only as stated.
     *
     * @return int lots moved
     */
    public function assign(int $variantId, int $generationId, ?string $modification, ?string $fuel, ?string $drive, ?string $complectation): int
    {
        $g = $this->db->table('ref_generations as g')->join('ref_models as m', 'm.id', '=', 'g.model_id')
            ->where('g.id', $generationId)->first(['g.id', 'g.model_id', 'm.make_id']);
        if ($g === null) {
            throw new \InvalidArgumentException("no generation {$generationId}");
        }
        $modification = trim((string) $modification);
        $complectation = trim((string) $complectation);
        $this->db->table('ref_variants')->where('id', $variantId)->update([
            'manual' => true,
            'status' => $modification !== '' ? 'matched' : 'no_engine',
            'make_id' => $g->make_id, 'model_id' => $g->model_id, 'generation_id' => $g->id,
            'modification_id' => $modification !== '' ? $this->store->modificationId($g->id, $modification, $fuel ?: null, $drive ?: null) : null,
            'complectation_id' => $complectation !== '' ? $this->store->complectationId((int) $g->make_id, $complectation) : null,
            'leftover' => null, 'reason' => 'set by hand',
            'updated_at' => now()->toDateTimeString(),
        ]);
        return $this->moveLots($variantId);
    }

    /** Gives a hand-placed variant back to the rules. */
    public function release(int $variantId): int
    {
        $v = $this->db->table('ref_variants')->where('id', $variantId)->first();
        if ($v === null) {
            return 0;
        }
        $this->db->table('ref_variants')->where('id', $variantId)->update(['manual' => false]);
        $this->store([(array) $v], again: true, lots: [$v->hash => (int) $v->lots]);
        return $this->moveLots($variantId);
    }

    /** The variant's lots take its current ids. */
    private function moveLots(int $variantId): int
    {
        return $this->db->update(
            'UPDATE lots l JOIN ref_variants v ON v.id = l.ref_variant_id
             SET l.ref_make_id = v.make_id, l.ref_model_id = v.model_id, l.ref_generation_id = v.generation_id,
                 l.ref_modification_id = v.modification_id, l.ref_complectation_id = v.complectation_id
             WHERE v.id = ?',
            [$variantId],
        );
    }

    /** A variant as the rules read it, with the catalog's ids. */
    private function read(string $source, array $v): array
    {
        $m = $this->matcher->match($v);

        $makeId = $m->make ? $this->store->makeId($m->make) : null;
        $modelId = $m->make && $m->model && $m->generation ? $this->store->modelId($m->make, $m->model) : null;
        $generationId = $modelId ? $this->store->generationId($m->make, $m->model, $m->generation) : null;
        $modificationId = $generationId && $m->modification
            ? $this->store->modificationId($generationId, $m->modification, $m->fuel, $m->drive) : null;
        $complectationId = $makeId && $m->complectation ? $this->store->complectationId($makeId, $m->complectation) : null;

        return [
            'make' => $v['make'] ?? null, 'model' => $v['model'] ?? null, 'badge_group' => $v['badge_group'] ?? null,
            'badge' => $v['badge'] ?? null, 'trim' => $v['trim'] ?? null,
            'status' => $m->status,
            'make_id' => $makeId, 'model_id' => $modelId, 'generation_id' => $generationId,
            'modification_id' => $modificationId, 'complectation_id' => $complectationId,
            'body' => $m->body, 'seats' => $m->seats,
            'editions' => $m->editions ? json_encode($m->editions, JSON_UNESCAPED_UNICODE) : null,
            'leftover' => $m->leftover ? json_encode($m->leftover, JSON_UNESCAPED_UNICODE) : null,
            'reason' => $m->reason ? mb_substr($m->reason, 0, 120) : null,
        ];
    }

    private static function differs(object $old, array $new): bool
    {
        foreach (['status', 'make_id', 'model_id', 'generation_id', 'modification_id', 'complectation_id'] as $f) {
            if ($old->{$f} != $new[$f]) {
                return true;
            }
        }
        return false;
    }
}
