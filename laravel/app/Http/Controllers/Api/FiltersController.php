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

            // makeOptions: keys are make_en (English) values — dropdown shows/searches English
            $makeOptions = array_map(static fn (string $v) => ['value' => $v, 'label' => $v], array_keys($makes));

            return [
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
                'damageTypes'         => [],
                'titleTypes'          => [],
            ];
        });

        return response()->json(['ok' => true, 'data' => $data]);
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

        // Apply progressive filters using English (make_en) for make.
        // Supports both: new lots (make_en set) and legacy lots (make_en NULL, make = English).
        // make → make_en
        if ($make !== '') {
            $base->where(function ($q) use ($make) {
                $q->where('make_en', $make)
                  ->orWhere(function ($q2) use ($make) {
                      $q2->whereNull('make_en')->where('make', $make);
                  });
            });
        }
        if ($modelGroup !== '') $base->where('model_group', $modelGroup);
        // model = model_group_en (Encar Level 3)
        if ($model !== '') {
            $base->where(function ($q) use ($model) {
                $q->where('model_group_en', $model)
                  ->orWhere(function ($q2) use ($model) {
                      $q2->whereNull('model_group_en')->whereRaw('model_group LIKE ?', ["%{$model}%"]);
                  });
            });
        }
        // badge = badge_en (fallback to badge when EN missing)
        if ($badge !== '') {
            $base->where(function ($q) use ($badge) {
                $q->where('badge_en', $badge)
                  ->orWhere(function ($q2) use ($badge) {
                      $q2->whereNull('badge_en')->where('badge', $badge);
                  });
            });
        }
        if ($trim !== '')       $base->where('trim', $trim);
        // generation → model_en (Encar Level 3 — full Model variant, e.g. "A5 (F5)")
        if ($generation !== '') {
            $base->whereRaw('model_en LIKE ?', ["%{$generation}%"]);
        }

        $makes       = $this->pluckEnMake(clone $base);
        $modelGroups = $this->pluck(clone $base, 'model_group');
        // Level 3: model_group_en (English ModelGroup)
        $models      = $this->pluckNonEmpty(clone $base, 'model_group_en');
        $badges      = $this->pluckCoalesced(clone $base, 'badge_en', 'badge');
        $trims       = $this->pluckFiltered(clone $base, 'trim', ['', '(세부등급 없음)']);
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
        $locale  = trim((string) $request->query('locale', 'ru')) ?: 'ru';
        $sources = config('auction.sources', ['encar', 'kbcha']);
        $sources = is_array($sources) ? $sources : ['encar', 'kbcha'];

        $query = DB::table('lots')
            ->where('is_active', true)
            ->whereIn('source', $sources)
            ->whereNotNull('trim')
            ->where('trim', '!=', '')
            ->where('trim', '!=', '(세부등급 없음)');

        // make_en
        if ($make !== '') {
            $query->where(function ($q) use ($make) {
                $q->where('make_en', $make)
                  ->orWhere(function ($q2) use ($make) {
                      $q2->whereNull('make_en')->where('make', $make);
                  });
            });
        }
        if ($modelGroup !== '') $query->where('model_group', $modelGroup);
        // model = model_group_en (Level 3 English)
        if ($model !== '') {
            $query->where(function ($q) use ($model) {
                $q->where('model_group_en', $model)
                  ->orWhere(function ($q2) use ($model) {
                      $q2->whereNull('model_group_en')
                         ->whereRaw('model_group LIKE ?', ["%{$model}%"]);
                  });
            });
        }

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
     * Build make_en → [model_en] hierarchy from lots.
     *
     * Uses lots.make_en (canonical English brand name filled by normalization)
     * and lots.model_en (canonical English model name from catalog_models).
     * Falls back to make / model (Korean) only when EN values are absent.
     *
     * @return array<string, string[]>   make_en → [model_en, ...]
     */
    private function buildMakesHierarchy(array $sources): array
    {
        $rows = DB::table('lots')
            ->where('is_active', true)
            ->whereIn('source', $sources)
            ->whereNotNull('make')->where('make', '!=', '')
            ->select(['make', 'make_en', 'model_group_en', 'model'])
            ->distinct()
            ->orderByRaw("COALESCE(NULLIF(TRIM(make_en),''), make)")
            ->get();

        $tree = [];
        foreach ($rows as $row) {
            $make     = trim((string) ($row->make_en ?? '')) ?: trim((string) ($row->make ?? ''));
            $modelKey = trim((string) ($row->model_group_en ?? ''));

            if ($make === '' || $modelKey === '') {
                continue;
            }

            $tree[$make][$modelKey] = true;
        }

        $result = [];
        ksort($tree);
        foreach ($tree as $make => $models) {
            ksort($models);
            $result[$make] = array_keys($models);
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

    /**
     * Pluck English make values: prefer make_en, fall back to make for legacy lots.
     *
     * @return string[]
     */
    private function pluckEnMake(Builder $query): array
    {
        return $query
            ->selectRaw("COALESCE(NULLIF(TRIM(make_en),''), NULLIF(TRIM(make),'')) AS _val")
            ->whereRaw("COALESCE(NULLIF(TRIM(make_en),''), NULLIF(TRIM(make),'')) IS NOT NULL")
            ->distinct()
            ->orderByRaw("COALESCE(NULLIF(TRIM(make_en),''), NULLIF(TRIM(make),''))")
            ->pluck('_val')
            ->map(static fn ($v) => trim((string) $v))
            ->filter(static fn (string $v) => $v !== '')
            ->values()->toArray();
    }

    /**
     * Pluck non-empty, non-null values from a column (no Korean fallback).
     *
     * @return string[]
     */
    private function pluckNonEmpty(Builder $query, string $column): array
    {
        return $query
            ->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column)
            ->map(static fn ($v) => trim((string) $v))
            ->filter(static fn (string $v) => $v !== '')
            ->values()->toArray();
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

    /**
     * Pluck COALESCE(primary, fallback) — returns primary when set, fallback otherwise.
     * Used for model_en (primary) falling back to model (Korean) when EN is missing.
     *
     * @return string[]
     */
    private function pluckCoalesced(Builder $query, string $primary, string $fallback): array
    {
        return $query
            ->selectRaw("COALESCE(NULLIF(TRIM({$primary}), ''), NULLIF(TRIM({$fallback}), '')) AS _val")
            ->whereRaw("COALESCE(NULLIF(TRIM({$primary}), ''), NULLIF(TRIM({$fallback}), '')) IS NOT NULL")
            ->distinct()
            ->orderByRaw("COALESCE(NULLIF(TRIM({$primary}), ''), NULLIF(TRIM({$fallback}), ''))")
            ->pluck('_val')
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
