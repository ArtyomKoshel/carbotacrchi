<?php

namespace App\Search\Specifications;

use App\Services\SearchQuery;
use Illuminate\Database\Query\Builder;

class ConditionSpec implements LotSpecification
{
    public function apply(Builder $query, SearchQuery $search): void
    {
        if ($search->insuranceCountMin !== null) $query->where('insurance_count', '>=', $search->insuranceCountMin);
        if ($search->insuranceCountMax !== null) $query->where('insurance_count', '<=', $search->insuranceCountMax);
        if ($search->ownersCountMin    !== null) $query->where('owners_count',    '>=', $search->ownersCountMin);
        if ($search->ownersCountMax    !== null) $query->where('owners_count',    '<=', $search->ownersCountMax);
        if ($search->seatCountMin) $query->where('seat_count', '>=', $search->seatCountMin);
        if ($search->seatCountMax) $query->where('seat_count', '<=', $search->seatCountMax);

        if ($search->hasAccident      !== null) $query->where('has_accident',      $search->hasAccident);
        if ($search->floodHistory     !== null) $query->where('flood_history',     $search->floodHistory);
        if ($search->totalLossHistory !== null) $query->where('total_loss_history', $search->totalLossHistory);
        if ($search->seatColor !== '')          $query->where('seat_color', 'like', '%' . $search->seatColor . '%');
    }
}
