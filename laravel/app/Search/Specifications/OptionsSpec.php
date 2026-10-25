<?php

namespace App\Search\Specifications;

use App\Services\SearchQuery;
use App\Support\OptionBits;
use Illuminate\Database\Query\Builder;

class OptionsSpec implements LotSpecification
{
    public function apply(Builder $query, SearchQuery $search): void
    {
        // Option codes, AND-logic: one bitmask test on the lots row
        // (see App\Support\OptionBits). Rows the backfill hasn't reached yet
        // (options_mask NULL) and codes without a mask bit use the JSON list.
        if ($search->options) {
            [$mask, $jsonOnly] = OptionBits::split($search->options);

            if ($mask !== 0) {
                $maskCodes = array_values(array_diff(
                    array_map('strval', $search->options),
                    $jsonOnly,
                ));
                $query->where(function (Builder $sub) use ($mask, $maskCodes): void {
                    $sub->whereRaw('(lots.options_mask & CAST(? AS UNSIGNED)) = CAST(? AS UNSIGNED)', [
                        OptionBits::unsigned($mask), OptionBits::unsigned($mask),
                    ])->orWhere(function (Builder $legacy) use ($maskCodes): void {
                        $legacy->whereNull('lots.options_mask')
                            ->whereRaw('JSON_CONTAINS(lots.options, ?)', [json_encode($maskCodes)]);
                    });
                });
            }

            foreach ($jsonOnly as $code) {
                $query->whereRaw('? MEMBER OF (lots.options)', [$code]);
            }
        }

        if ($search->vin) {
            $query->where('vin', $search->vin);
        }
    }
}
