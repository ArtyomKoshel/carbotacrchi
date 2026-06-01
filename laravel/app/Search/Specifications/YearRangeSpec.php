<?php

namespace App\Search\Specifications;

use App\Services\SearchQuery;
use Illuminate\Database\Query\Builder;

class YearRangeSpec implements LotSpecification
{
    public function apply(Builder $query, SearchQuery $search): void
    {
        if ($search->yearFrom) $query->where('year', '>=', $search->yearFrom);
        if ($search->yearTo)   $query->where('year', '<=', $search->yearTo);
    }
}
