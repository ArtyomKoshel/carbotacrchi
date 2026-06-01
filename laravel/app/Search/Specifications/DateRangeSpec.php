<?php

namespace App\Search\Specifications;

use App\Services\SearchQuery;
use Illuminate\Database\Query\Builder;

class DateRangeSpec implements LotSpecification
{
    public function apply(Builder $query, SearchQuery $search): void
    {
        if ($search->listedAfter)    $query->where('listed_at',      '>=', $search->listedAfter);
        if ($search->listedBefore)   $query->where('listed_at',      '<=', $search->listedBefore);
        if ($search->firstRegAfter)  $query->where('first_reg_date', '>=', $search->firstRegAfter);
        if ($search->firstRegBefore) $query->where('first_reg_date', '<=', $search->firstRegBefore);
    }
}
