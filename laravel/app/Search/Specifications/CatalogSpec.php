<?php

namespace App\Search\Specifications;

use App\Services\SearchQuery;
use Illuminate\Database\Query\Builder;

/**
 * The reference catalog's filters (lots.ref_*, app/Catalog): make → model → generations →
 * modifications, and complectations. Several generations, modifications or complectations
 * are any of them.
 */
class CatalogSpec implements LotSpecification
{
    public function apply(Builder $query, SearchQuery $search): void
    {
        if ($search->refMakeId) {
            $query->where('ref_make_id', $search->refMakeId);
        }
        if ($search->refModelId) {
            $query->where('ref_model_id', $search->refModelId);
        }
        if ($search->refGenerationIds) {
            $query->whereIn('ref_generation_id', $search->refGenerationIds);
        }
        if ($search->refModificationIds) {
            $query->whereIn('ref_modification_id', $search->refModificationIds);
        }
        if ($search->refComplectationIds) {
            $query->whereIn('ref_complectation_id', $search->refComplectationIds);
        }
    }
}
