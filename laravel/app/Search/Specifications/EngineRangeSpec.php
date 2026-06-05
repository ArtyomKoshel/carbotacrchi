<?php

namespace App\Search\Specifications;

use App\Services\SearchQuery;
use Illuminate\Database\Query\Builder;

class EngineRangeSpec implements LotSpecification
{
    public function apply(Builder $query, SearchQuery $search): void
    {
        if ($search->engineMin) $query->where('engine_volume', '>=', $search->engineMin);
        if ($search->engineMax) $query->where('engine_volume', '<=', $search->engineMax);
        if ($search->repairCostMin) $query->where('repair_cost', '>=', $search->repairCostMin);
        if ($search->repairCostMax) $query->where('repair_cost', '<=', $search->repairCostMax);
        if ($search->retailValueMin) $query->where('retail_value', '>=', $search->retailValueMin);
        if ($search->retailValueMax) $query->where('retail_value', '<=', $search->retailValueMax);
    }
}
