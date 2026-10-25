<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotFilterSetting;
use App\Models\EncarOptionCatalog;
use App\Search\Specifications\CatalogSpec;
use App\Services\ProviderAggregator;
use App\Services\SearchQuery;
use App\Support\Taxonomy\TaxonomyLocalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FiltersController extends Controller
{
    public function __construct(private readonly ProviderAggregator $aggregator) {}

    public function count(Request $request): JsonResponse
    {
        $query = SearchQuery::fromArray($request->input('query', []));
        return response()->json(['ok' => true, 'data' => ['count' => $this->aggregator->count($query)]]);
    }

    public function index(): JsonResponse
    {
        $locale      = trim((string) request()->query('locale', 'ru')) ?: 'ru';
        $sourcesCfg  = config('auction.sources', ['encar', 'kbcha']);
        $sources     = array_values(is_array($sourcesCfg) ? $sourcesCfg : ['encar', 'kbcha']);
        $currentYear = (int) date('Y');

        $cacheKey = 'api_filters_' . $locale . '_' . implode('_', $sources);

        $data = Cache::remember($cacheKey, 600, function () use ($locale, $sources, $currentYear) {
            $makes         = [];
            $bodyTypes     = [];
            $transmissions = [];
            $fuelTypes     = [];
            $driveTypes    = [];
            $colors        = [];
            $filterFields  = [];
            $optionItems   = [];

            try {
                $makes         = $this->catalogMakes();
                $bodyTypes     = $this->distinctValues('body_type', $sources);
                $transmissions = $this->distinctValues('transmission', $sources);
                $fuelTypes     = $this->distinctValues('fuel', $sources);
                $driveTypes    = $this->distinctValues('drive_type', $sources);
                $colors        = $this->distinctValues('color', $sources);
                $filterFields  = $this->buildFilterFieldsMeta();
                $optionItems   = $this->buildOptionItems($locale);
            } catch (\Throwable) {
            }

            return [
                'makeOptions'         => array_map(static fn (string $v) => ['value' => $v, 'label' => $v], $makes),
                'sources'             => $this->buildSourceOptions($sources),
                'cardFields'          => BotFilterSetting::getCardFields(),
                'filterFields'        => $filterFields,
                'years'               => range($currentYear, 2000),
                'bodyTypes'           => $bodyTypes,
                'bodyTypeOptions'     => TaxonomyLocalizer::options('body_type', $bodyTypes, $locale),
                'transmissions'       => $transmissions,
                'transmissionOptions' => TaxonomyLocalizer::options('transmission', $transmissions, $locale),
                'fuelTypes'           => $fuelTypes,
                'fuelTypeOptions'     => TaxonomyLocalizer::options('fuel', $fuelTypes, $locale),
                'driveTypes'          => $driveTypes,
                'driveTypeOptions'    => TaxonomyLocalizer::options('drive_type', $driveTypes, $locale),
                'colors'              => $colors,
                'colorOptions'        => TaxonomyLocalizer::options('color', $colors, $locale),
                'options'             => $optionItems,
                'damageTypes'         => [],
                'titleTypes'          => [],
            ];
        });

        return response()->json(['ok' => true, 'data' => $data]);
    }

    /**
     * The flat filters' values among the active lots of the chosen make / model of the reference
     * catalog (refMakeId / refModelId, or a make by name): body, fuel, drive, transmission, colour.
     */
    public function context(Request $request): JsonResponse
    {
        $locale = trim((string) $request->query('locale', 'ru')) ?: 'ru';
        $search = SearchQuery::fromArray($request->only(['make', 'refMakeId', 'refModelId']));

        $columns = ['body_type', 'fuel', 'drive_type', 'transmission', 'color'];
        $key = "api_filters_context_{$search->refMakeId}_{$search->refModelId}_" . (int) $search->matchesNothing;
        // one pass over the make's lots for all five lists
        $values = Cache::remember($key, 600, function () use ($search, $columns) {
            $base = DB::table('lots')->where('is_active', true);
            (new CatalogSpec())->apply($base, $search);
            $rows = $base->select($columns)->distinct()->get();
            $out = [];
            foreach ($columns as $c) {
                $out[$c] = $rows->pluck($c)->map(static fn ($v) => trim((string) $v))
                    ->filter(static fn (string $v) => $v !== '')->unique()->sort()->values()->all();
            }
            return $out;
        });
        ['body_type' => $bodyTypes, 'fuel' => $fuelTypes, 'drive_type' => $driveTypes,
            'transmission' => $transmissions, 'color' => $colors] = $values;

        return response()->json([
            'ok' => true,
            'data' => [
                'bodyTypes'           => $bodyTypes,
                'bodyTypeOptions'     => TaxonomyLocalizer::options('body_type', $bodyTypes, $locale),
                'fuelTypes'           => $fuelTypes,
                'fuelTypeOptions'     => TaxonomyLocalizer::options('fuel', $fuelTypes, $locale),
                'driveTypes'          => $driveTypes,
                'driveTypeOptions'    => TaxonomyLocalizer::options('drive_type', $driveTypes, $locale),
                'transmissions'       => $transmissions,
                'transmissionOptions' => TaxonomyLocalizer::options('transmission', $transmissions, $locale),
                'colors'              => $colors,
                'colorOptions'        => TaxonomyLocalizer::options('color', $colors, $locale),
            ],
        ]);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Makes of the reference catalog that have active lots, by the lot counts of the last
     * catalog:remap (no scan of lots).
     *
     * @return string[]
     */
    private function catalogMakes(): array
    {
        return DB::table('ref_makes as k')->join('ref_variants as v', 'v.make_id', '=', 'k.id')
            ->where('v.lots', '>', 0)->distinct()->orderBy('k.name')->pluck('k.name')->all();
    }

    /** @return string[] */
    private function distinctValues(string $column, array $sources): array
    {
        return DB::table('lots')
            ->where('is_active', true)
            ->whereIn('source', $sources)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()->orderBy($column)
            ->pluck($column)
            ->map(static fn ($v) => trim((string) $v))
            ->filter(static fn (string $v) => $v !== '')
            ->values()->unique()->sort()->values()->toArray();
    }

    private function buildSourceOptions(array $sources): array
    {
        $labels = ['encar' => 'Encar', 'kbcha' => 'KBChacha'];
        return array_map(static fn (string $key) => [
            'key'  => $key,
            'name' => $labels[$key] ?? strtoupper($key),
        ], $sources);
    }

    private function buildOptionItems(string $locale): array
    {
        $catalog = EncarOptionCatalog::getCached();
        if ($catalog->isEmpty()) return [];

        return $catalog->sortBy('sort_order')
            ->map(function ($opt) use ($locale) {
                $label = match ($locale) {
                    'ru'    => $opt->name_ru ?? $opt->name_en ?? $opt->name_kr ?? $opt->code,
                    'en'    => $opt->name_en ?? $opt->name_ru ?? $opt->name_kr ?? $opt->code,
                    default => $opt->name_kr ?? $opt->name_en ?? $opt->name_ru ?? $opt->code,
                };
                return ['value' => $opt->code, 'label' => $label,
                        'icon_url' => $opt->icon_url, 'category' => $opt->category];
            })->values()->toArray();
    }

    private function buildFilterFieldsMeta(): array
    {
        return BotFilterSetting::orderBy('sort_order')->orderBy('field_name')
            ->get(['field_name', 'field_label', 'dtype', 'category', 'enabled', 'display_in_card'])
            ->map(static fn (BotFilterSetting $s) => [
                'name'          => $s->field_name,
                'label'         => $s->field_label,
                'dtype'         => $s->dtype,
                'category'      => $s->category,
                'enabled'       => (bool) $s->enabled,
                'displayInCard' => (bool) $s->display_in_card,
            ])->values()->toArray();
    }
}
