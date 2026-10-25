<?php

namespace App\Catalog;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The reference catalog in the database (ref_makes … ref_complectations): ids by name,
 * the row made from the reference when it is not there yet. Modifications have no list of
 * their own — a modification is stored the first time a variant reads as it.
 */
class CatalogStore
{
    /** @var array<string, int> */
    private array $ids = [];

    private readonly ConnectionInterface $db;

    public function __construct(private readonly Reference $reference, ?ConnectionInterface $db = null)
    {
        $this->db = $db ?? DB::connection();
    }

    /**
     * Writes every make, model, generation and complectation of the reference (new ones
     * added, years / body brought up to date). Nothing is deleted: lots keep their ids.
     *
     * @return array{makes: int, models: int, generations: int, complectations: int}
     */
    public function sync(): array
    {
        $now = now()->toDateTimeString();
        $stamp = ['created_at' => $now, 'updated_at' => $now];
        $makes = [];
        foreach ($this->reference->makeNames() as $name) {
            $makes[] = ['make' => $name, ...array_diff_key($this->reference->make($name), ['make' => 1])];
        }

        // level by level: what is missing goes in by batches, then every id is read back at once
        $this->preload();
        $this->insertMissing('ref_makes', array_map(fn (array $m) => ['name' => $m['make']], $makes), fn (array $r) => "make|{$r['name']}", $stamp);
        $models = $complectations = [];
        foreach ($makes as $m) {
            $makeId = $this->ids["make|{$m['make']}"];
            foreach ($m['complectations'] ?? [] as $c) {
                $complectations["{$makeId}|{$c}"] = ['make_id' => $makeId, 'name' => $c];
            }
            foreach ($m['models'] as $model) {
                $models[] = ['make_id' => $makeId, 'name' => $model['name'], 'body' => $model['body'] ?? null, 'fuel' => $model['fuel'] ?? null];
            }
        }
        $this->insertMissing('ref_complectations', array_values($complectations), fn (array $r) => "complectation|{$r['make_id']}|{$r['name']}", $stamp);
        $byId = [];
        foreach ($makes as $m) {
            $byId[$this->ids["make|{$m['make']}"]] = $m['make'];
        }
        $modelKey = fn (array $r) => "model|{$byId[$r['make_id']]}|{$r['name']}";
        $this->insertMissing('ref_models', $models, $modelKey, $stamp);

        $generations = [];
        foreach ($makes as $m) {
            foreach ($m['models'] as $model) {
                $modelId = $this->ids["model|{$m['make']}|{$model['name']}"];
                foreach ($model['generations'] as $g) {
                    $generations["{$modelId}|{$g['code']}"] = ['model_id' => $modelId, 'code' => $g['code'], ...self::generationAttributes($g),
                        'key' => "generation|{$m['make']}|{$model['name']}|{$g['code']}"];
                }
            }
        }
        $this->insertMissing('ref_generations', array_map(fn (array $g) => array_diff_key($g, ['key' => 1]), array_values($generations)),
            fn (array $r) => $generations["{$r['model_id']}|{$r['code']}"]['key'], $stamp);

        // years / body / fuel brought up to date in batches
        foreach (array_chunk($models, 500) as $chunk) {
            $this->db->table('ref_models')->upsert(array_map(fn (array $r) => ['id' => $this->ids[$modelKey($r)], ...$r, 'updated_at' => $now], $chunk),
                ['id'], ['body', 'fuel', 'updated_at']);
        }
        foreach (array_chunk(array_values($generations), 500) as $chunk) {
            $this->db->table('ref_generations')->upsert(array_map(fn (array $g) => ['id' => $this->ids[$g['key']], ...array_diff_key($g, ['key' => 1]), 'updated_at' => $now], $chunk),
                ['id'], ['number', 'year_from', 'year_to', 'fuel', 'updated_at']);
        }

        return ['makes' => count($makes), 'models' => count($models), 'generations' => count($generations), 'complectations' => count($complectations)];
    }

    /** Rows not known by their cache key are inserted in batches; then every id is read again. */
    private function insertMissing(string $table, array $rows, callable $key, array $stamp): void
    {
        $missing = array_values(array_filter($rows, fn (array $r) => ! isset($this->ids[$key($r)])));
        foreach (array_chunk($missing, 500) as $chunk) {
            $this->db->table($table)->insertOrIgnore(array_map(fn (array $r) => [...$r, ...$stamp], $chunk));
        }
        if ($missing !== []) {
            $this->preload();
        }
    }

    /** The reference's model of that name as stored, without making one for a name the reference does not have. */
    public function referenceModelId(string $make, string $model): ?int
    {
        return $this->reference->model($make, $model) ? $this->modelId($make, $model) : null;
    }

    /** Every stored id at once, so reading many variants asks the database nothing more. */
    public function preload(): void
    {
        $makes = $this->db->table('ref_makes')->pluck('name', 'id');
        foreach ($makes as $id => $name) {
            $this->ids["make|{$name}"] = (int) $id;
        }
        $models = [];
        foreach ($this->db->table('ref_models')->get(['id', 'make_id', 'name']) as $m) {
            $models[$m->id] = [$makes[$m->make_id] ?? '', $m->name];
            $this->ids["model|{$makes[$m->make_id]}|{$m->name}"] = (int) $m->id;
        }
        foreach ($this->db->table('ref_generations')->get(['id', 'model_id', 'code']) as $g) {
            [$make, $model] = $models[$g->model_id] ?? ['', ''];
            $this->ids["generation|{$make}|{$model}|{$g->code}"] = (int) $g->id;
        }
        foreach ($this->db->table('ref_modifications')->get(['id', 'generation_id', 'code']) as $d) {
            $this->ids["modification|{$d->generation_id}|{$d->code}"] = (int) $d->id;
        }
        foreach ($this->db->table('ref_complectations')->get(['id', 'make_id', 'name']) as $c) {
            $this->ids["complectation|{$c->make_id}|{$c->name}"] = (int) $c->id;
        }
    }

    public function makeId(string $make): int
    {
        return $this->id("make|{$make}", 'ref_makes', ['name' => $make]);
    }

    public function modelId(string $make, string $model): int
    {
        $ref = $this->reference->model($make, $model);
        return $this->id("model|{$make}|{$model}", 'ref_models', ['make_id' => $this->makeId($make), 'name' => $model],
            ['body' => $ref['body'] ?? null, 'fuel' => $ref['fuel'] ?? null]);
    }

    public function generationId(string $make, string $model, string $code): int
    {
        $ref = $this->reference->model($make, $model);
        $generation = $ref ? $this->reference->generation($ref, $code) : null;
        return $this->id("generation|{$make}|{$model}|{$code}", 'ref_generations',
            ['model_id' => $this->modelId($make, $model), 'code' => $code],
            $generation ? self::generationAttributes($generation) : []);
    }

    public function modificationId(int $generationId, string $code, ?string $fuel, ?string $drive): int
    {
        return $this->id("modification|{$generationId}|{$code}", 'ref_modifications',
            ['generation_id' => $generationId, 'code' => $code], ['fuel' => $fuel, 'drive' => $drive]);
    }

    public function complectationId(int $makeId, string $name): int
    {
        return $this->id("complectation|{$makeId}|{$name}", 'ref_complectations', ['make_id' => $makeId, 'name' => $name]);
    }

    private static function generationAttributes(array $g): array
    {
        return [
            'number' => $g['number'] ?? null,
            'year_from' => $g['years'][0] ?? null,
            'year_to' => $g['years'][1] ?? null,
            'fuel' => $g['fuel'] ?? null,
        ];
    }

    /** The row's id by its unique key, inserted with $extra when missing. */
    private function id(string $cacheKey, string $table, array $key, array $extra = []): int
    {
        if (isset($this->ids[$cacheKey])) {
            return $this->ids[$cacheKey];
        }
        $id = $this->db->table($table)->where($key)->value('id');
        if ($id === null) {
            $now = now()->toDateTimeString();
            $this->db->table($table)->insertOrIgnore([...$key, ...$extra, 'created_at' => $now, 'updated_at' => $now]);
            $id = $this->db->table($table)->where($key)->value('id');
        }
        return $this->ids[$cacheKey] = (int) $id;
    }
}
