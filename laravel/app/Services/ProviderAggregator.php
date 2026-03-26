<?php

namespace App\Services;

use App\AuctionProviders\ProviderInterface;
use App\Dto\LotDTO;

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

    public function search(SearchQuery $query): SearchResult
    {
        $errors = [];
        $lots   = [];

        foreach ($this->getActiveProviders($query->sources) as $provider) {
            array_push($lots, ...$provider->search($query));
            if (!$provider->isAvailable()) {
                $errors[] = $provider->getKey();
            }
        }

        $lots  = $this->sort($lots, $query->sort);
        $total = count($lots);
        $lots  = array_slice($lots, $query->offset, $query->limit);

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

    /** @param LotDTO[] $lots */
    private function sort(array $lots, string $by): array
    {
        usort($lots, fn (LotDTO $a, LotDTO $b) => match ($by) {
            'price_asc'  => $a->price <=> $b->price,
            'price_desc' => $b->price <=> $a->price,
            default      => strcmp($b->auctionDate ?? '', $a->auctionDate ?? ''),
        });

        return $lots;
    }
}
