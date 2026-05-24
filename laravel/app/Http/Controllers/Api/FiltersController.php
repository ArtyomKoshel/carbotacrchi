<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotFilterSetting;
use App\Support\Taxonomy\TaxonomyLocalizer;
use App\Support\Taxonomy\TaxonomyNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FiltersController extends Controller
{
    public function index(): JsonResponse
    {
        $locale = trim((string) request()->query('locale', 'ru')) ?: 'ru';

        $makes = [];

        $currentYear = (int) date('Y');

        $sourcesCfg = config('auction.sources', ['encar', 'kbcha']);
        $sources = array_values(is_array($sourcesCfg) ? $sourcesCfg : ['encar', 'kbcha']);

        $sourceOptions = $this->buildSourceOptions($sources);

        $cardFields = BotFilterSetting::getCardFields();

        $bodyTypes = [];
        $transmissions = [];
        $fuelTypes = [];
        $driveTypes = [];
        $colors = [];
        $generations = [];
        $filterFields = [];

        try {
            $makes = $this->buildMakesFromLots($sources);

            $bodyTypes = $this->distinctStrings('body_type', $sources, 'body_type');
            $transmissions = $this->distinctStrings('transmission', $sources, 'transmission');
            $fuelTypes = $this->distinctStrings('fuel', $sources, 'fuel');
            $driveTypes = $this->distinctStrings('drive_type', $sources, 'drive_type');
            $colors = $this->distinctStrings('color', $sources);
            $generations = $this->distinctStrings('generation', $sources);
            $filterFields = $this->buildFilterFieldsMeta();
        } catch (\Throwable) {
        }

        $makeValues = array_keys($makes);
        $makeOptions = array_map(static fn (string $v) => ['value' => $v, 'label' => $v], $makeValues);
        $bodyTypeOptions = TaxonomyLocalizer::options('body_type', $bodyTypes, $locale);
        $transmissionOptions = TaxonomyLocalizer::options('transmission', $transmissions, $locale);
        $fuelTypeOptions = TaxonomyLocalizer::options('fuel', $fuelTypes, $locale);
        $driveTypeOptions = TaxonomyLocalizer::options('drive_type', $driveTypes, $locale);

        return response()->json([
            'ok'   => true,
            'data' => [
                'makes'   => $makes,
                'makeOptions' => $makeOptions,
                'sources' => $sourceOptions,
                'cardFields' => $cardFields,
                'filterFields' => $filterFields,
                'years'         => range($currentYear, 2000),
                'damageTypes'   => [],
                'titleTypes'    => [],
                'bodyTypes'     => $bodyTypes,
                'bodyTypeOptions' => $bodyTypeOptions,
                'transmissions' => $transmissions,
                'transmissionOptions' => $transmissionOptions,
                'fuelTypes'     => $fuelTypes,
                'fuelTypeOptions' => $fuelTypeOptions,
                'driveTypes'    => $driveTypes,
                'driveTypeOptions' => $driveTypeOptions,
                'colors'        => $colors,
                'colorOptions'  => TaxonomyLocalizer::options('color', $colors, $locale),
                'generations'   => $generations,
            ],
        ]);
    }

    /** @return array<int, array{key: string, name: string}> */
    private function buildSourceOptions(array $sources): array
    {
        $sourceLabels = [
            'encar' => 'Encar',
            'kbcha' => 'KBChacha',
        ];

        return array_map(static fn (string $key) => [
            'key' => $key,
            'name' => $sourceLabels[$key] ?? strtoupper($key),
        ], $sources);
    }

    /** @return string[] */
    private function distinctStrings(string $column, array $sources, ?string $taxonomyField = null): array
    {
        $values = DB::table('lots')
            ->where('is_active', true)
            ->whereIn('source', $sources)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(static fn ($v) => trim((string) $v))
            ->filter(static fn (string $v) => $v !== '')
            ->values()
            ->toArray();

        if ($taxonomyField !== null) {
            $values = TaxonomyNormalizer::normalizeMany($taxonomyField, $values);
        }

        sort($values);

        return array_values(array_unique($values));
    }

    /** @return array<string, string[]> */
    private function buildMakesFromLots(array $sources): array
    {
        $rows = DB::table('lots')
            ->where('is_active', true)
            ->whereIn('source', $sources)
            ->whereNotNull('make')
            ->where('make', '!=', '')
            ->select(['make', 'model'])
            ->orderBy('make')
            ->orderBy('model')
            ->get();

        $byMake = [];
        foreach ($rows as $row) {
            $makeRaw = trim((string) ($row->make ?? ''));
            $make = TaxonomyNormalizer::normalize('make', $makeRaw);
            if ($make === '') {
                continue;
            }

            $model = trim((string) ($row->model ?? ''));
            $byMake[$make] ??= [];
            if ($model !== '') {
                $byMake[$make][$model] = true;
            }
        }

        $result = [];
        foreach ($byMake as $make => $models) {
            $modelNames = array_keys($models);
            sort($modelNames);
            $result[$make] = $modelNames;
        }
        ksort($result);

        return $result;
    }

    public function trims(Request $request): JsonResponse
    {
        $make = trim((string) $request->query('make', ''));
        $model = trim((string) $request->query('model', ''));
        $sources = config('auction.sources', ['encar', 'kbcha']);
        $sources = is_array($sources) ? $sources : ['encar', 'kbcha'];

        $query = DB::table('lots')
            ->where('is_active', true)
            ->whereIn('source', $sources)
            ->whereNotNull('trim')
            ->where('trim', '!=', '')
            ->where('trim', '!=', '(세부등급 없음)');

        if ($make !== '') {
            $this->applyCanonicalEqFilter($query, 'make', 'make', $make);
        }
        if ($model !== '') {
            $query->where('model', $model);
        }

        $trims = $query->distinct()
            ->orderBy('trim')
            ->pluck('trim')
            ->map(static fn ($v) => trim((string) $v))
            ->filter(static fn (string $v) => $v !== '')
            ->values()
            ->toArray();

        return response()->json(['ok' => true, 'data' => ['trims' => array_values(array_unique($trims))]]);
    }

    public function context(Request $request): JsonResponse
    {
        $locale = trim((string) $request->query('locale', 'ru')) ?: 'ru';
        $status = trim((string) $request->query('status', 'all'));
        $source = trim((string) $request->query('source', ''));
        $make = trim((string) $request->query('make', ''));
        $model = trim((string) $request->query('model', ''));
        $trim = trim((string) $request->query('trim', ''));
        $generation = trim((string) $request->query('generation', ''));

        $makesQuery = $this->adminLotsBaseQuery($status, $source);
        $makes = $this->pluckDistinct($makesQuery, 'make', 'make');

        $modelsQuery = $this->adminLotsBaseQuery($status, $source);
        if ($make !== '') {
            $this->applyCanonicalEqFilter($modelsQuery, 'make', 'make', $make);
        }
        $models = $this->pluckDistinct($modelsQuery, 'model');

        $trimsQuery = $this->adminLotsBaseQuery($status, $source)
            ->whereNotNull('trim')
            ->where('trim', '!=', '')
            ->where('trim', '!=', '(세부등급 없음)');
        if ($make !== '') {
            $this->applyCanonicalEqFilter($trimsQuery, 'make', 'make', $make);
        }
        if ($model !== '') {
            $trimsQuery->where('model', $model);
        }
        $trims = $this->pluckDistinct($trimsQuery, 'trim');

        $generationsQuery = $this->adminLotsBaseQuery($status, $source);
        if ($make !== '') {
            $this->applyCanonicalEqFilter($generationsQuery, 'make', 'make', $make);
        }
        if ($model !== '') {
            $generationsQuery->where('model', $model);
        }
        if ($trim !== '') {
            $generationsQuery->where('trim', $trim);
        }
        $generations = $this->pluckDistinct($generationsQuery, 'generation');

        $detailQuery = $this->adminLotsBaseQuery($status, $source);
        if ($make !== '') {
            $this->applyCanonicalEqFilter($detailQuery, 'make', 'make', $make);
        }
        if ($model !== '') {
            $detailQuery->where('model', $model);
        }
        if ($trim !== '') {
            $detailQuery->where('trim', $trim);
        }
        if ($generation !== '') {
            $detailQuery->where('generation', $generation);
        }

        $bodyTypes = $this->pluckDistinct(clone $detailQuery, 'body_type', 'body_type');
        $transmissions = $this->pluckDistinct(clone $detailQuery, 'transmission', 'transmission');
        $fuelTypes = $this->pluckDistinct(clone $detailQuery, 'fuel', 'fuel');
        $driveTypes = $this->pluckDistinct(clone $detailQuery, 'drive_type', 'drive_type');

        $colors = $this->pluckDistinct(clone $detailQuery, 'color');

        return response()->json([
            'ok' => true,
            'data' => [
                'makes' => $makes,
                'makeOptions' => array_map(static fn (string $v) => ['value' => $v, 'label' => $v], $makes),
                'models' => $models,
                'modelOptions' => array_map(static fn (string $v) => ['value' => $v, 'label' => $v], $models),
                'trims' => $trims,
                'trimOptions' => array_map(static fn (string $v) => ['value' => $v, 'label' => $v], $trims),
                'generations' => $generations,
                'generationOptions' => array_map(static fn (string $v) => ['value' => $v, 'label' => $v], $generations),
                'bodyTypes' => $bodyTypes,
                'bodyTypeOptions' => TaxonomyLocalizer::options('body_type', $bodyTypes, $locale),
                'transmissions' => $transmissions,
                'transmissionOptions' => TaxonomyLocalizer::options('transmission', $transmissions, $locale),
                'fuelTypes' => $fuelTypes,
                'fuelTypeOptions' => TaxonomyLocalizer::options('fuel', $fuelTypes, $locale),
                'driveTypes' => $driveTypes,
                'driveTypeOptions' => TaxonomyLocalizer::options('drive_type', $driveTypes, $locale),
                'colors' => $colors,
                'colorOptions' => TaxonomyLocalizer::options('color', $colors, $locale),
            ],
        ]);
    }

    private function adminLotsBaseQuery(string $status, string $source): Builder
    {
        $q = DB::table('lots');

        if ($status === 'active') {
            $q->where('is_active', true);
        } elseif ($status === 'inactive') {
            $q->where('is_active', false);
        }

        if ($source !== '') {
            $q->where('source', $source);
        }

        return $q;
    }

    /** @return string[] */
    private function pluckDistinct(Builder $query, string $column, ?string $taxonomyField = null): array
    {
        $values = $query
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(static fn ($v) => trim((string) $v))
            ->filter(static fn (string $v) => $v !== '')
            ->values()
            ->toArray();

        if ($taxonomyField !== null) {
            $values = TaxonomyNormalizer::normalizeMany($taxonomyField, $values);
        }

        sort($values);

        return array_values(array_unique($values));
    }

    private function applyCanonicalEqFilter(Builder $query, string $column, string $taxonomyField, string $selected): void
    {
        $variants = TaxonomyNormalizer::expandToDbValues($taxonomyField, $selected);
        if ($variants === []) {
            return;
        }

        $query->whereIn($column, $variants);
    }

    /** @return array<int, array<string, mixed>> */
    private function buildFilterFieldsMeta(): array
    {
        return BotFilterSetting::orderBy('sort_order')
            ->orderBy('field_name')
            ->get(['field_name', 'field_label', 'dtype', 'category', 'enabled', 'display_in_card'])
            ->map(static fn (BotFilterSetting $s) => [
                'name' => $s->field_name,
                'label' => $s->field_label,
                'dtype' => $s->dtype,
                'category' => $s->category,
                'enabled' => (bool) $s->enabled,
                'displayInCard' => (bool) $s->display_in_card,
            ])
            ->values()
            ->toArray();
    }
}
