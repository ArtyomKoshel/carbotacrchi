<?php

namespace App\Search\Specifications;

use App\Services\SearchQuery;
use Illuminate\Database\Query\Builder;

class MileageRangeSpec implements LotSpecification
{
    public function apply(Builder $query, SearchQuery $search): void
    {
        if ($search->mileageMin) $query->where('mileage', '>=', $search->mileageMin);
        if ($search->mileageMax) $query->where('mileage', '<=', $search->mileageMax);
    }
}
