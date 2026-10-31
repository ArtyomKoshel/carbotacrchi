<?php

namespace App\Services;

use App\AuctionProviders\ProviderInterface;
use App\Search\Specifications\CatalogSpec;
use App\Search\Specifications\ConditionSpec;
use App\Search\Specifications\DateRangeSpec;
use App\Search\Specifications\EngineRangeSpec;
use App\Search\Specifications\LotSpecification; // phpstan: used as return type hint in docblock
use App\Search\Specifications\MileageRangeSpec;
use App\Search\Specifications\OptionsSpec;
use App\Search\Specifications\PriceRangeSpec;
use App\Search\Specifications\TaxonomySpec;
use App\Search\Specifications\YearRangeSpec;
use App\Catalog\Che168\ChaboshiReport;
use App\Support\Currency;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ProviderAggregator
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    public function register(ProviderInterface ...$providers): static
    {
        foreach ($providers as $p) {
            $this->providers[$p->getKey()] = $p;
        }

        return $this;
    }

    public function provider(string $key): ?ProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    public function count(SearchQuery $query): int
    {
        $activeSources = array_values(array_filter(
            $query->sources,
            fn ($key) => isset($this->providers[$key]) && $this->providers[$key]->isAvailable()
        ));

        if (empty($activeSources)) {
            return 0;
        }

        $builder = DB::table('lots')
            ->where('is_active', true)
            ->whereIn('source', $activeSources);

        $this->applyDbFilters($builder, $query);

        return (int) $builder->count();
    }

    public function search(SearchQuery $query): SearchResult
    {
        $errors = [];
        foreach ($query->sources as $sourceKey) {
            $provider = $this->providers[$sourceKey] ?? null;
            if ($provider === null || !$provider->isAvailable()) {
                $errors[] = (string) $sourceKey;
            }
        }
        $errors = array_values(array_unique($errors));

        $activeProviders = $this->getActiveProviders($query->sources);
        $providersByKey = [];
        foreach ($activeProviders as $provider) {
            $providersByKey[$provider->getKey()] = $provider;
        }

        if ($providersByKey === []) {
            return new SearchResult([], 0, $errors);
        }

        $builder = DB::table('lots')
            ->where('is_active', true)
            ->whereIn('source', array_keys($providersByKey));

        $this->applyDbFilters($builder, $query);

        $total = (clone $builder)->count();

        $this->applyDbSort($builder, $query->sort, $query->sort2, Currency::shared(array_keys($providersByKey)) === null);

        $rows = $builder
            ->offset($query->offset)
            ->limit($query->limit)
            ->get();

        $lots = [];
        // a Che168 car's Chaboshi report in short, for the list's history label: one query per page
        $reports = app(ChaboshiReport::class)->summaries(
            $rows->where('source', 'che168')->pluck('id')->map(fn ($id) => (string) $id)->values()->all());
        foreach ($rows as $row) {
            $raw = (array) $row;
            $raw['inspection_summary'] = $reports[(string) $raw['id']] ?? null;
            $source = (string) ($raw['source'] ?? '');
            $provider = $providersByKey[$source] ?? null;
            if ($provider === null) {
                continue;
            }

            $lots[] = $provider->normalize($raw);
        }

        return new SearchResult($lots, $total, $errors);
    }

    /** @return ProviderInterface[] */
    private function getActiveProviders(array $keys): array
    {
        return array_values(array_filter(
            array_map(fn ($k) => $this->providers[$k] ?? null, $keys),
            fn ($p) => $p !== null && $p->isAvailable()
        ));
    }

    private function applyDbSort(Builder $builder, string $sort, string $sort2 = '', bool $mixedCurrencies = false): void
    {
        // every order the miniapp offers (SearchQuery::SORTS); id last keeps pages stable
        $then = $sort2 !== '' && $sort2 !== $sort;
        // ₩ and ¥ are not compared: by price means by price within each currency, KRW (Encar) first
        if ($mixedCurrencies && (str_starts_with($sort, 'price') || ($then && str_starts_with($sort2, 'price')))) {
            $builder->orderBy('currency', 'desc');
        }
        match ($sort) {
            'price_asc' => $builder->orderBy('price', 'asc'),
            'price_desc' => $builder->orderBy('price', 'desc'),
            'mileage_asc' => $builder->orderBy('mileage', 'asc'),
            'mileage_desc' => $builder->orderBy('mileage', 'desc'),
            'year_desc' => $builder->orderBy('year', 'desc'),
            'year_asc' => $builder->orderBy('year', 'asc'),
            // listing times almost never tie, so a second order would never apply: "newest" goes by day then
            default => $then ? $builder->orderByRaw('DATE(listed_at) DESC') : $builder->orderBy('listed_at', 'desc'),
        };
        if ($then) {
            $this->applySecondarySort($builder, $sort2);
        }
        if (in_array($sort, ['year_desc', 'year_asc'], true) && ! in_array($sort2, ['mileage_asc', 'mileage_desc'], true)) {
            $builder->orderBy('mileage', 'asc');
        }
        $builder->orderBy('id', 'desc');
    }

    private function applySecondarySort(Builder $builder, string $sort2): void
    {
        match ($sort2) {
            'price_asc' => $builder->orderBy('price', 'asc'),
            'price_desc' => $builder->orderBy('price', 'desc'),
            'mileage_asc' => $builder->orderBy('mileage', 'asc'),
            'mileage_desc' => $builder->orderBy('mileage', 'desc'),
            'year_desc' => $builder->orderBy('year', 'desc'),
            'year_asc' => $builder->orderBy('year', 'asc'),
            'date' => $builder->orderBy('listed_at', 'desc'),
            default => null,
        };
    }

    /** @return LotSpecification[] */
    private function specifications(): array
    {
        return [
            new CatalogSpec(),
            new YearRangeSpec(),
            new PriceRangeSpec(),
            new MileageRangeSpec(),
            new EngineRangeSpec(),
            new ConditionSpec(),
            new TaxonomySpec(),
            new OptionsSpec(),
            new DateRangeSpec(),
        ];
    }

    private function applyDbFilters(Builder $builder, SearchQuery $query): void
    {
        foreach ($this->specifications() as $spec) {
            $spec->apply($builder, $query);
        }
    }
}
