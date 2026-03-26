<?php

namespace App\Repositories;

use App\Dto\LotDTO;
use App\Services\ProviderAggregator;
use App\Services\SearchQuery;
use App\Services\SearchResult;

class MockLotRepository implements LotRepositoryInterface
{
    public function __construct(
        private readonly ProviderAggregator $aggregator,
    ) {}

    public function search(SearchQuery $query): SearchResult
    {
        return $this->aggregator->search($query);
    }

    public function findById(string $id): ?LotDTO
    {
        $query        = new SearchQuery();
        $query->limit = 500;

        $result = $this->aggregator->search($query);

        foreach ($result->lots as $lot) {
            if ($lot->id === $id) {
                return $lot;
            }
        }

        return null;
    }
}
