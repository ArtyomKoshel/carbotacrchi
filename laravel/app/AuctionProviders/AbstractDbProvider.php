<?php

namespace App\AuctionProviders;

use App\Services\SearchQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

abstract class AbstractDbProvider extends AbstractProvider
{
    private const MAX_ROWS = 2000;

    public function isAvailable(): bool
    {
        return true;
    }

    public function fetchRaw(SearchQuery $query): array
    {
        return [];
    }

    public function search(SearchQuery $query): array
    {
        try {
            $builder = DB::table('lots')
                ->where('source', $this->getKey())
                ->where('is_active', true);

            $this->applyDbFilters($builder, $query);
            $this->applyDbSort($builder, $query->sort);

            $limit = min(
                self::MAX_ROWS,
                max(50, $query->offset + $query->limit + 50)
            );

            return $builder
                ->limit($limit)
                ->get()
                ->map(fn ($row) => $this->normalize((array) $row))
                ->toArray();
        } catch (\Throwable $e) {
            Log::error("[{$this->getKey()}] DB search error: " . $e->getMessage());
            return [];
        }
    }

    private function applyDbSort(Builder $builder, string $sort): void
    {
        match ($sort) {
            'price_asc' => $builder->orderBy('price', 'asc'),
            'price_desc' => $builder->orderBy('price', 'desc'),
            default => $builder->orderBy('registration_date', 'desc')->orderBy('id', 'desc'),
        };
    }

    private function applyDbFilters(Builder $builder, SearchQuery $query): void
    {
        if ($query->make)  $builder->whereRaw('make LIKE ?', [$query->make . '%']);
        if ($query->model) $builder->whereRaw('model_en LIKE ?', ['%' . $query->model . '%']);

        if ($query->yearFrom)   $builder->where('year', '>=', $query->yearFrom);
        if ($query->yearTo)     $builder->where('year', '<=', $query->yearTo);
        if ($query->priceMin)   $builder->where('price', '>=', $query->priceMin);
        if ($query->priceMax)   $builder->where('price', '<=', $query->priceMax);
        if ($query->mileageMin) $builder->where('mileage', '>=', $query->mileageMin);
        if ($query->mileageMax) $builder->where('mileage', '<=', $query->mileageMax);
        if ($query->engineMin)  $builder->where('engine_volume', '>=', $query->engineMin);
        if ($query->engineMax)  $builder->where('engine_volume', '<=', $query->engineMax);

        if ($query->repairCostMin)  $builder->where('repair_cost', '>=', $query->repairCostMin);
        if ($query->repairCostMax)  $builder->where('repair_cost', '<=', $query->repairCostMax);
        if ($query->retailValueMin) $builder->where('retail_value', '>=', $query->retailValueMin);
        if ($query->retailValueMax) $builder->where('retail_value', '<=', $query->retailValueMax);

        if ($query->ownersCountMin !== null)    $builder->where('owners_count', '>=', $query->ownersCountMin);
        if ($query->ownersCountMax !== null)    $builder->where('owners_count', '<=', $query->ownersCountMax);
        if ($query->insuranceCountMin !== null) $builder->where('insurance_count', '>=', $query->insuranceCountMin);
        if ($query->insuranceCountMax !== null) $builder->where('insurance_count', '<=', $query->insuranceCountMax);

        if ($query->seatCountMin) $builder->where('seat_count', '>=', $query->seatCountMin);
        if ($query->seatCountMax) $builder->where('seat_count', '<=', $query->seatCountMax);

        if ($query->registrationYearMonthMin) $builder->where('registration_year_month', '>=', $query->registrationYearMonthMin);
        if ($query->registrationYearMonthMax) $builder->where('registration_year_month', '<=', $query->registrationYearMonthMax);

        if ($query->hasAccident !== null)      $builder->where('has_accident', $query->hasAccident);
        if ($query->floodHistory !== null)     $builder->where('flood_history', $query->floodHistory);
        if ($query->totalLossHistory !== null) $builder->where('total_loss_history', $query->totalLossHistory);

        if ($query->transmissions) $builder->whereIn('transmission', $query->transmissions);
        if ($query->fuelTypes)     $builder->whereIn('fuel', $query->fuelTypes);
        if ($query->bodyTypes)     $builder->whereIn('body_type', $query->bodyTypes);
        if ($query->driveTypes)    $builder->whereIn('drive_type', $query->driveTypes);
        if ($query->colors)        $builder->whereIn('color', $query->colors);
        if ($query->lienStatuses)  $builder->whereIn('lien_status', $query->lienStatuses);
        if ($query->seizureStatuses) $builder->whereIn('seizure_status', $query->seizureStatuses);
        if ($query->sellTypes)     $builder->whereIn('sell_type', $query->sellTypes);

        if ($query->vin) $builder->where('vin', $query->vin);
    }
}
