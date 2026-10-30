<?php

namespace App\Catalog\Che168;

use App\Catalog\Reference;
use App\Catalog\VariantMatch;

/**
 * Places a Che168 variant (brandname, seriesname as Che168 names them) on the reference catalog:
 * the make and the model, nothing more. Che168 never names the generation, so none is guessed
 * (not even from the model year): such a variant stays `unmatched` under its model, and the
 * review queue can place it by hand.
 *
 * resources/catalog/che168/brands.json maps a brand to the reference make and names series the
 * rules below cannot read («亚洲龙» → Avalon). The rules read Che168's own spellings:
 * the brand before the series («宝马X5»), «(进口)» / «新能源» after it, BMW's «5系»,
 * Mercedes' «E级», and the long-wheelbase «L» of Chinese-built Audis («A6L» → A6).
 */
class Che168Matcher
{
    /** @var array<string, array{make: string, series?: array<string, string>}>|null */
    private ?array $brands = null;

    public function __construct(private readonly Reference $reference, private readonly ?string $file = null) {}

    /** @param array{make?: ?string, model?: ?string} $lot a lots row or a variant of them */
    public function match(array $lot): VariantMatch
    {
        $brand = trim((string) ($lot['make'] ?? ''));
        $series = trim((string) ($lot['model'] ?? ''));
        $known = $this->brands()[$brand] ?? null;
        $ref = $known ? $this->reference->make($known['make']) : null;
        if ($ref === null) {
            return new VariantMatch(VariantMatch::UNMATCHED, reason: 'make not in reference');
        }
        $make = $ref['make'];
        $model = $this->model($make, $brand, $series, $known['series'] ?? []);

        return new VariantMatch(VariantMatch::UNMATCHED, $make, $model, reason: $model === null
            ? mb_substr("model {$series} not in reference", 0, 120)
            : 'Che168 names no generation');
    }

    /** @param array<string, string> $named */
    private function model(string $make, string $brand, string $series, array $named): ?string
    {
        if (isset($named[$series])) {
            return $named[$series];
        }
        $name = preg_replace('/\s*[(（]进口[)）]$|新能源$/u', '', $series);
        if (isset($named[$name])) {
            return $named[$name];
        }
        if (str_starts_with($name, $brand) && mb_strlen($name) > mb_strlen($brand)) {
            $name = trim(mb_substr($name, mb_strlen($brand)));
        }
        $candidates = [$name];
        if (preg_match('/^(\d)系$/u', $name, $m)) {
            $candidates[] = "{$m[1]} Series";
        }
        if (preg_match('/^([A-Z]{1,3})级$/u', $name, $m)) {
            $candidates[] = "{$m[1]}-Class";
        }
        if (preg_match('/^[A-Z]{2,3}$/', $name)) {
            $candidates[] = "{$name}-Class"; // Mercedes «GLC» → GLC-Class
        }
        if (preg_match('/^([A-Z]\d)L$/', $name, $m)) {
            $candidates[] = $m[1]; // Audi «A6L», «Q5L»: the long Chinese body of A6, Q5
        }
        foreach ($candidates as $candidate) {
            $model = $this->reference->model($make, $candidate);
            if ($model !== null) {
                return $model['name'];
            }
        }

        return null;
    }

    /** @return array<string, array{make: string, series?: array<string, string>}> */
    private function brands(): array
    {
        return $this->brands ??= json_decode(
            (string) file_get_contents($this->file ?? resource_path('catalog/che168/brands.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['brands'];
    }
}
