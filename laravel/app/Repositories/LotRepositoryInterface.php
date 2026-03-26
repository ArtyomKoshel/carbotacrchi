<?php

namespace App\Repositories;

use App\Dto\LotDTO;
use App\Services\SearchQuery;
use App\Services\SearchResult;

interface LotRepositoryInterface
{
    public function search(SearchQuery $query): SearchResult;

    public function findById(string $id): ?LotDTO;
}
