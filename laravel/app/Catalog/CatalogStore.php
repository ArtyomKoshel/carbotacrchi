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
        $counts = ['makes' => 0, 'models' => 0, 'generations' => 0, 'complectations' => 0];
        foreach ($this->reference->makeNames() as $name) {
            $make = $this->reference->make($name);
            $makeId = $this->makeId($name);
            $counts['makes']++;
            foreach ($make['complectations'] ?? [] as $c) {
                $this->complectationId($makeId, $c);
                $counts['complectations']++;
            }
            foreach ($make['models'] as $model) {
                $modelId = $this->modelId($name, $model['name']);
                $this->db->table('ref_models')->where('id', $modelId)
                    ->update(['body' => $model['body'] ?? null, 'fuel' => $model['fuel'] ?? null, 'updated_at' => $now]);
                $counts['models']++;
                foreach ($model['generations'] as $g) {
                    $id = $this->generationId($name, $model['name'], $g['code']);
                    $this->db->table('ref_generations')->where('id', $id)->update([...self::generationAttributes($g), 'updated_at' => $now]);
                    $counts['generations']++;
                }
            }
        }
        return $counts;
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
