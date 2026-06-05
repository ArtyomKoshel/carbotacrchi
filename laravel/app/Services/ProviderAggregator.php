<?php

namespace App\Services;

use App\AuctionProviders\ProviderInterface;
use App\Search\Specifications\ConditionSpec;
use App\Search\Specifications\DateRangeSpec;
use App\Search\Specifications\EngineRangeSpec;
use App\Search\Specifications\LotSpecification; // phpstan: used as return type hint in docblock
use App\Search\Specifications\MakeModelSpec;
use App\Search\Specifications\MileageRangeSpec;
use App\Search\Specifications\OptionsSpec;
use App\Search\Specifications\PriceRangeSpec;
use App\Search\Specifications\TaxonomySpec;
use App\Search\Specifications\YearRangeSpec;
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

        $this->applyDbSort($builder, $query->sort);

        $rows = $builder
            ->offset($query->offset)
            ->limit($query->limit)
            ->get();

        $lots = [];
        foreach ($rows as $row) {
            $raw = (array) $row;
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

    private function applyDbSort(Builder $builder, string $sort): void
    {
        match ($sort) {
            'price_asc' => $builder->orderBy('price', 'asc'),
            'price_desc' => $builder->orderBy('price', 'desc'),
            default => $builder->orderBy('listed_at', 'desc')->orderBy('id', 'desc'),
        };
    }

    /** @return LotSpecification[] */
    private function specifications(): array
    {
        return [
            new MakeModelSpec(),
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
