<?php

namespace App\Search\Specifications;

use App\Services\SearchQuery;
use Illuminate\Database\Query\Builder;

class PriceRangeSpec implements LotSpecification
{
    public function apply(Builder $query, SearchQuery $search): void
    {
        if ($search->priceMin) $query->where('price', '>=', $search->priceMin);
        if ($search->priceMax) $query->where('price', '<=', $search->priceMax);
    }
}
