<?php

namespace App\Search\Specifications;

use App\Services\SearchQuery;
use Illuminate\Database\Query\Builder;

/**
 * Prices are in the lot's own currency (lots.currency) and never converted: a range in ₩
 * finds only lots priced in ₩, whichever platforms are selected.
 */
class PriceRangeSpec implements LotSpecification
{
    public function apply(Builder $query, SearchQuery $search): void
    {
        if (! $search->priceMin && ! $search->priceMax) {
            return;
        }
        $query->where('currency', $search->priceCurrency);
        if ($search->priceMin) $query->where('price', '>=', $search->priceMin);
        if ($search->priceMax) $query->where('price', '<=', $search->priceMax);
    }
}
