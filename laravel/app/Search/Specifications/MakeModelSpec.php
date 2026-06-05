<?php

namespace App\Search\Specifications;

use App\Services\SearchQuery;
use Illuminate\Database\Query\Builder;

class MakeModelSpec implements LotSpecification
{
    public function apply(Builder $query, SearchQuery $search): void
    {
        // make → make_en (English brand, e.g. "Audi")
        // Legacy lots without make_en fall through to the make column (Korean).
        if ($search->make) {
            $query->where(function (Builder $sub) use ($search): void {
                $sub->where('make_en', $search->make)
                    ->orWhere(function (Builder $sub2) use ($search): void {
                        $sub2->whereNull('make_en')
                             ->whereRaw('make LIKE ?', [$search->make . '%']);
                    });
            });
        }

        // model → model_group_en (Encar Level 3: ModelGroup English, e.g. "A5")
        if ($search->model) {
            $query->where(function (Builder $sub) use ($search): void {
                $sub->whereRaw('model_group_en LIKE ?', ['%' . $search->model . '%'])
                    ->orWhere(function (Builder $sub2) use ($search): void {
                        $sub2->whereNull('model_group_en')
                             ->whereRaw('model_group LIKE ?', ['%' . $search->model . '%']);
                    });
            });
        }

        // generation → model_en (Encar Level 3: full variant, e.g. "A5 (F5)", "The New K8")
        if ($search->generation) {
            $query->whereRaw('model_en LIKE ?', ['%' . $search->generation . '%']);
        }

        // badge → badge_en (with fallback to badge)
        if ($search->badge) {
            $query->where(function (Builder $sub) use ($search): void {
                $sub->whereRaw('badge_en LIKE ?', ['%' . $search->badge . '%'])
                    ->orWhere(function (Builder $sub2) use ($search): void {
                        $sub2->whereNull('badge_en')
                             ->whereRaw('badge LIKE ?', ['%' . $search->badge . '%']);
                    });
            });
        }

        if ($search->trim) {
            $query->where('trim', $search->trim);
        }
    }
}
