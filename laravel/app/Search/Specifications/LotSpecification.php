<?php

namespace App\Search\Specifications;

use App\Services\SearchQuery;
use Illuminate\Database\Query\Builder;

interface LotSpecification
{
    public function apply(Builder $query, SearchQuery $search): void;
}
