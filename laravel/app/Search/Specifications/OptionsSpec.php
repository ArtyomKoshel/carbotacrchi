<?php

namespace App\Search\Specifications;

use App\Models\Translation;
use App\Services\SearchQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class OptionsSpec implements LotSpecification
{
    public function apply(Builder $query, SearchQuery $search): void
    {
        // Option codes: AND-logic using indexed pivot table (replaces slow JSON_CONTAINS).
        // Each required option narrows the lot set via a subquery EXISTS check.
        foreach ($search->options as $code) {
            $code = (string) $code;
            $query->whereExists(function ($sub) use ($code): void {
                $sub->select(DB::raw(1))
                    ->from('lot_options')
                    ->whereColumn('lot_options.lot_id', 'lots.id')
                    ->where('lot_options.option_code', $code);
            });
        }

        if ($search->trim) {
            $krVariants = Translation::resolveKorean($search->trim, 'trim');
            if (!empty($krVariants)) {
                $query->where(function (Builder $sub) use ($search, $krVariants): void {
                    $sub->whereIn('trim', $krVariants)->orWhere('trim', $search->trim);
                });
            } else {
                $query->where(function (Builder $sub) use ($search): void {
                    $sub->where('trim', $search->trim)
                        ->orWhere('trim', 'like', '%' . $search->trim . '%');
                });
            }
        }

        if ($search->vin) {
            $query->where('vin', $search->vin);
        }
    }
}
