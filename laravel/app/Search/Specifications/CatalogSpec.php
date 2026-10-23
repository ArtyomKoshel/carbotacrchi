<?php

namespace App\Search\Specifications;

use App\Services\SearchQuery;
use Illuminate\Database\Query\Builder;

/**
 * The reference catalog's filters (lots.ref_*, app/Catalog): make → model → generations →
 * modifications, and complectations. Several generations, modifications or complectations
 * are any of them; the id 0 is "not stated" (NULL), so a choice never hides those lots silently.
 */
class CatalogSpec implements LotSpecification
{
    public function apply(Builder $query, SearchQuery $search): void
    {
        if ($search->refMakeId) {
            $query->where('ref_make_id', $search->refMakeId);
        }
        if ($search->refModelId === -1) {
            $query->whereNull('ref_model_id'); // «Прочие модели»: the make is known, the model is not
        } elseif ($search->refModelId) {
            $query->where('ref_model_id', $search->refModelId);
        }
        self::anyOf($query, 'ref_generation_id', $search->refGenerationIds);
        self::anyOf($query, 'ref_modification_id', $search->refModificationIds);
        self::anyOf($query, 'ref_complectation_id', $search->refComplectationIds);
    }

    /** @param int[] $ids 0 stands for NULL */
    private static function anyOf(Builder $query, string $column, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $known = array_values(array_filter($ids, fn (int $id) => $id > 0));
        $none = in_array(0, $ids, true);
        $query->where(function (Builder $q) use ($column, $known, $none) {
            if ($known) {
                $q->whereIn($column, $known);
            }
            if ($none) {
                $q->orWhereNull($column);
            }
        });
    }
}
