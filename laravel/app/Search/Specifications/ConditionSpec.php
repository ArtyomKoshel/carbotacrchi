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

        // the same rule as EncarProvider::hasAccident: claims are an accident, «no accident» needs a record without claims
        if ($search->hasAccident === true)  $query->where(fn ($q) => $q->where('has_accident', true)->orWhere('insurance_count', '>', 0));
        if ($search->hasAccident === false) $query->whereRaw('COALESCE(has_accident, 0) = 0')->where('insurance_count', 0);
        if ($search->accidentUnknown)       $query->whereRaw('COALESCE(has_accident, 0) = 0')->whereNull('insurance_count');
        if ($search->floodHistory     !== null) $query->where('flood_history',     $search->floodHistory);
        if ($search->totalLossHistory !== null) $query->where('total_loss_history', $search->totalLossHistory);
        if ($search->seatColor !== '')          $query->where('seat_color', 'like', '%' . $search->seatColor . '%');
    }
}
