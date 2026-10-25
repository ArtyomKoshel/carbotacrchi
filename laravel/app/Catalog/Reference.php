<?php

namespace App\Catalog;

use Illuminate\Support\Str;

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

    /**
     * By name, any case, a space, a hyphen or a slash alike, accents dropped ("Land Rover",
     * "land-rover"; "Citroën/DS", "citroen-ds" — the file's name).
     */
    public function make(string $make): ?array
    {
        return $this->makes()[self::key($make)] ?? null;
    }

    private static function key(string $make): string
    {
        return strtolower(preg_replace('/[\s\/-]+/', '-', Str::ascii(trim($make))));
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
     * A model's generation by chassis code (or one of its aliases) or by number ("#2", the
     * generation's "number"). Without either, only a model of one generation has an answer:
     * the year is never used to guess one, the years of two generations overlap.
     */
    public function generation(array $model, ?string $code): ?array
    {
        $generations = $model['generations'] ?? [];
        if ($code !== null && $code !== '') {
            foreach ($generations as $g) {
                if (strcasecmp($g['code'], $code) === 0 || in_array(strtoupper($code), $g['aliases'] ?? [], true)
                    || (isset($g['number']) && $code === "#{$g['number']}")) {
                    return $g;
                }
            }
            return null;
        }
        return count($generations) === 1 ? $generations[0] : null;
    }

    private function makes(): array
    {
        if ($this->makes !== null) {
            return $this->makes;
        }
        $this->makes = [];
        foreach (glob(($this->dir ?? resource_path('catalog/reference')) . '/*.json') as $file) {
            $make = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $this->makes[self::key($make['make'])] = $make;
        }
        return $this->makes;
    }
}
