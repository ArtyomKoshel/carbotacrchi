<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotFilterSetting;
use App\Models\EncarOptionCatalog;
use App\Services\ProviderAggregator;
use App\Services\SearchQuery;
use App\Support\Taxonomy\TaxonomyLocalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
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
        $locale     = trim((string) request()->query('locale', 'ru')) ?: 'ru';
        $currentYear = (int) date('Y');
        $sourcesCfg  = config('auction.sources', ['encar', 'kbcha']);
        $sources     = array_values(is_array($sourcesCfg) ? $sourcesCfg : ['encar', 'kbcha']);

        $makes         = [];
        $bodyTypes     = [];
        $transmissions = [];
        $fuelTypes     = [];
        $driveTypes    = [];
        $colors        = [];
        $filterFields  = [];
        $optionItems   = [];

        try {
            $makes         = $this->buildMakesHierarchy($sources);
            $bodyTypes     = $this->distinctValues('body_type', $sources);
            $transmissions = $this->distinctValues('transmission', $sources);
            $fuelTypes     = $this->distinctValues('fuel', $sources);
            $driveTypes    = $this->distinctValues('drive_type', $sources);
            $colors        = $this->distinctValues('color', $sources);
            $filterFields  = $this->buildFilterFieldsMeta();
            $optionItems   = $this->buildOptionItems($locale);
        } catch (\Throwable) {
        }

        $makeOptions = array_map(static fn (string $v) => ['value' => $v, 'label' => $v], array_keys($makes));

        return response()->json([
            'ok'   => true,
            'data' => [
                'makes'               => $makes,
                'makeOptions'         => $makeOptions,
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
                // Deprecated, kept for backward compat
                'damageTypes'         => [],
                'titleTypes'          => [],
                'generations'         => [],
            ],
        ]);
    }

    /**
     * Cascading filter context: given current selections, return available next-level values.
     *
     * Supports full iNav hierarchy:
     *   make → model_group → model → badge → trim
     * Plus flat filters: body_type, fuel, drive_type, transmission, color, year, price
     */
    public function context(Request $request): JsonResponse
    {
        $locale      = trim((string) $request->query('locale', 'ru')) ?: 'ru';
        $status      = trim((string) $request->query('status', 'all'));
        $source      = trim((string) $request->query('source', ''));
        $make        = trim((string) $request->query('make', ''));
        $modelGroup  = trim((string) $request->query('model_group', ''));
        $model       = trim((string) $request->query('model', ''));
        $badge       = trim((string) $request->query('badge', ''));
        $trim        = trim((string) $request->query('trim', ''));
        $generation  = trim((string) $request->query('generation', ''));

        $base = $this->baseQuery($status, $source);

        // Apply progressive filters (cascade)
        if ($make !== '')       $base->where('make', $make);
        if ($modelGroup !== '') $base->where('model_group', $modelGroup);
        if ($model !== '')      $base->where('model', $model);
        if ($badge !== '')      $base->where('badge', $badge);
        if ($trim !== '')       $base->where('trim', $trim);
        if ($generation !== '') $base->where('generation', $generation);

        // Each level returns what's available WITHIN current selection
        $makes         = $this->pluck(clone $base, 'make');
        $modelGroups   = $this->pluck(clone $base->when($make !== '', fn ($q) => $q->where('make', $make)), 'model_group');
        $models        = $this->pluck(clone $base, 'model');
        $badges        = $this->pluck(clone $base, 'badge');
        $trims         = $this->pluckFiltered(clone $base, 'trim', ['', '(세부등급 없음)']);
        $generations   = $this->pluck(clone $base, 'generation');
        $bodyTypes     = $this->pluck(clone $base, 'body_type');
        $fuelTypes     = $this->pluck(clone $base, 'fuel');
        $driveTypes    = $this->pluck(clone $base, 'drive_type');
        $transmissions = $this->pluck(clone $base, 'transmission');
        $colors        = $this->pluck(clone $base, 'color');

        return response()->json([
            'ok' => true,
            'data' => [
                'makes'               => $makes,
                'makeOptions'         => array_map(static fn ($v) => ['value' => $v, 'label' => $v], $makes),
                'modelGroups'         => $modelGroups,
                'modelGroupOptions'   => array_map(static fn ($v) => ['value' => $v, 'label' => $v], $modelGroups),
                'models'              => $models,
                'modelOptions'        => array_map(static fn ($v) => ['value' => $v, 'label' => $v], $models),
                'badges'              => $badges,
                'badgeOptions'        => array_map(static fn ($v) => ['value' => $v, 'label' => $v], $badges),
                'trims'               => $trims,
                'trimOptions'         => TaxonomyLocalizer::trimOptions($trims, $locale),
                'generations'         => $generations,
                'generationOptions'   => array_map(static fn ($v) => ['value' => $v, 'label' => $v], $generations),
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

    public function trims(Request $request): JsonResponse
    {
        $make    = trim((string) $request->query('make', ''));
        $modelGroup = trim((string) $request->query('model_group', ''));
        $model   = trim((string) $request->query('model', ''));
        $locale  = trim((string) $request->query('locale', 'en')) ?: 'en';
        $sources = config('auction.sources', ['encar', 'kbcha']);
        $sources = is_array($sources) ? $sources : ['encar', 'kbcha'];

        $query = DB::table('lots')
            ->where('is_active', true)
            ->whereIn('source', $sources)
            ->whereNotNull('trim')
            ->where('trim', '!=', '')
            ->where('trim', '!=', '(세부등급 없음)');

        if ($make !== '')       $query->where('make', $make);
        if ($modelGroup !== '') $query->where('model_group', $modelGroup);
        if ($model !== '')      $query->where('model', $model);

        $trims = $query->distinct()->orderBy('trim')->pluck('trim')
            ->map(static fn ($v) => trim((string) $v))
            ->filter(static fn (string $v) => $v !== '')
            ->values()->toArray();

        return response()->json(['ok' => true, 'data' => [
            'trims'       => $trims,
            'trimOptions' => TaxonomyLocalizer::trimOptions($trims, $locale),
        ]]);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Build cascading make → model_group → model hierarchy from lots.
     * Used by the top-level /filters endpoint for pre-loading all options.
     *
     * @return array<string, array<string, string[]>>
     *   make → { model_group → [model, model, ...] }
     */
    private function buildMakesHierarchy(array $sources): array
    {
        $rows = DB::table('lots')
            ->where('is_active', true)
            ->whereIn('source', $sources)
            ->whereNotNull('make')->where('make', '!=', '')
            ->select(['make', 'model_group', 'model'])
            ->orderBy('make')->orderBy('model_group')->orderBy('model')
            ->get();

        $tree = [];
        foreach ($rows as $row) {
            $make  = trim((string) ($row->make ?? ''));
            $mg    = trim((string) ($row->model_group ?? '')) ?: '_';
            $model = trim((string) ($row->model ?? ''));
            if ($make === '') continue;

            $tree[$make][$mg][$model] = true;
        }

        // Sort and convert to arrays
        $result = [];
        ksort($tree);
        foreach ($tree as $make => $groups) {
            ksort($groups);
            $result[$make] = [];
            foreach ($groups as $mg => $models) {
                $modelList = array_keys($models);
                sort($modelList);
                $result[$make][$mg] = $modelList;
            }
        }

        return $result;
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

    /** @return string[] */
    private function pluck(Builder $query, string $column): array
    {
        return $query->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column)
            ->map(static fn ($v) => trim((string) $v))
            ->filter(static fn (string $v) => $v !== '')
            ->values()->toArray();
    }

    /** @return string[] */
    private function pluckFiltered(Builder $query, string $column, array $exclude): array
    {
        return $query->whereNotNull($column)->where($column, '!=', '')
            ->whereNotIn($column, $exclude)
            ->distinct()->orderBy($column)->pluck($column)
            ->map(static fn ($v) => trim((string) $v))
            ->filter(static fn (string $v) => $v !== '')
            ->values()->toArray();
    }

    private function baseQuery(string $status, string $source): Builder
    {
        $q = DB::table('lots');
        if ($status === 'active')   $q->where('is_active', true);
        if ($status === 'inactive') $q->where('is_active', false);
        if ($source !== '')         $q->where('source', $source);
        return $q;
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
