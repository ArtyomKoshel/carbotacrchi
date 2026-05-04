<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotFilterSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class FiltersController extends Controller
{
    public function index(): JsonResponse
    {
        $makesFile = storage_path('app/data/makes_models.json');
        $makes = file_exists($makesFile)
            ? json_decode(file_get_contents($makesFile), true) ?? []
            : [];

        $currentYear = (int) date('Y');

        $sources = array_values(config('auction.sources', ['encar', 'kbcha']));

        $sourceOptions = $this->buildSourceOptions($sources);

        $cardFields = BotFilterSetting::getCardFields();

        $bodyTypes = [];
        $transmissions = [];
        $fuelTypes = [];
        $driveTypes = [];
        $filterFields = [];

        try {
            $dynamicMakes = $this->buildMakesFromLots($sources);
            if ($dynamicMakes !== []) {
                $makes = $dynamicMakes;
            }

            $bodyTypes = $this->distinctStrings('body_type', $sources);
            $transmissions = $this->distinctStrings('transmission', $sources);
            $fuelTypes = $this->distinctStrings('fuel', $sources);
            $driveTypes = $this->distinctStrings('drive_type', $sources);
            $filterFields = $this->buildFilterFieldsMeta();
        } catch (\Throwable) {
        }

        return response()->json([
            'ok'   => true,
            'data' => [
                'makes'   => $makes,
                'sources' => $sourceOptions,
                'cardFields' => $cardFields,
                'filterFields' => $filterFields,
                'years'         => range($currentYear, 2000),
                'damageTypes'   => [],
                'titleTypes'    => [],
                'bodyTypes'     => $bodyTypes,
                'transmissions' => $transmissions,
                'fuelTypes'     => $fuelTypes,
                'driveTypes'    => $driveTypes,
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
    private function distinctStrings(string $column, array $sources): array
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
            ->select(['make', 'model_en'])
            ->orderBy('make')
            ->orderBy('model_en')
            ->get();

        $byMake = [];
        foreach ($rows as $row) {
            $make = trim((string) ($row->make ?? ''));
            if ($make === '') {
                continue;
            }

            $model = trim((string) ($row->model_en ?? ''));
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
