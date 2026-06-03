<?php

namespace App\Search\Specifications;

use App\Services\SearchQuery;
use App\Support\Taxonomy\TaxonomyNormalizer;
use Illuminate\Database\Query\Builder;

class MakeModelSpec implements LotSpecification
{
    public function apply(Builder $query, SearchQuery $search): void
    {
        if ($search->make) {
            $variants = TaxonomyNormalizer::expandToDbValues('make', $search->make);
            $query->where(function (Builder $sub) use ($variants, $search): void {
                if ($variants !== []) {
                    $sub->whereIn('make', $variants)
                        ->orWhereRaw('make LIKE ?', [$search->make . '%']);
                    return;
                }
                $sub->whereRaw('make LIKE ?', [$search->make . '%']);
            });
        }

        if ($search->model) {
            $query->where(function (Builder $sub) use ($search): void {
                // Prefer model_en (English, filled by normalize command) — covers AI search.
                // Fall back to model (Korean raw) for legacy/direct Korean queries.
                $sub->where('model_en', $search->model)
                    ->orWhere('model', $search->model);
            });
        }

        if ($search->generation) {
            $query->whereRaw('generation LIKE ?', ['%' . $search->generation . '%']);
        }
    }
}
