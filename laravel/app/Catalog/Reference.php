<?php

namespace App\Catalog;

/**
 * The reference catalog: makes → models → generations, plus each make's names of
 * complectations (equipment lines such as "M Sport") and special editions.
 *
 * It belongs to no platform. Files live in resources/catalog/reference/{make}.json;
 * a platform maps its own fields onto it (Encar: App\Catalog\Encar\EncarMatcher).
 */
class Reference
{
    /** @var array<string, array>|null make (lower case) → make file */
    private ?array $makes = null;

    public function __construct(private readonly ?string $dir = null) {}

    public function make(string $make): ?array
    {
        return $this->makes()[mb_strtolower(trim($make))] ?? null;
    }

    /** @return list<string> */
    public function makeNames(): array
    {
        return array_values(array_map(fn (array $m) => $m['make'], $this->makes()));
    }

    public function model(string $make, string $model): ?array
    {
        foreach ($this->make($make)['models'] ?? [] as $m) {
            if (strcasecmp($m['name'], $model) === 0) {
                return $m;
            }
        }
        return null;
    }

    /**
     * A model's generation by chassis code (or one of its aliases). Without a code: the
     * only generation, else the one whose years hold $year.
     */
    public function generation(array $model, ?string $code, ?int $year = null): ?array
    {
        $generations = $model['generations'] ?? [];
        if ($code !== null && $code !== '') {
            foreach ($generations as $g) {
                if (strcasecmp($g['code'], $code) === 0 || in_array(strtoupper($code), $g['aliases'] ?? [], true)) {
                    return $g;
                }
            }
            return null;
        }
        if (count($generations) === 1) {
            return $generations[0];
        }
        if ($year === null) {
            return null;
        }
        $hits = array_values(array_filter($generations, fn (array $g) => self::covers($g, $year)));
        return count($hits) === 1 ? $hits[0] : null;
    }

    public static function covers(array $generation, int $year, int $slack = 0): bool
    {
        [$from, $to] = $generation['years'] + [null, null];
        return ($from === null || $year >= $from - $slack) && ($to === null || $year <= $to + $slack);
    }

    private function makes(): array
    {
        if ($this->makes !== null) {
            return $this->makes;
        }
        $this->makes = [];
        foreach (glob(($this->dir ?? resource_path('catalog/reference')) . '/*.json') as $file) {
            $make = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $this->makes[mb_strtolower($make['make'])] = $make;
        }
        return $this->makes;
    }
}
