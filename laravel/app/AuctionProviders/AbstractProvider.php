<?php

namespace App\AuctionProviders;

use App\Dto\LotDTO;
use App\Services\SearchQuery;
use Illuminate\Support\Facades\Log;

abstract class AbstractProvider implements ProviderInterface
{
    protected bool $available = true;

    protected static array $transmissionMap = [
        'auto'      => 'Automatic', 'automatic' => 'Automatic',
        'manual'    => 'Manual',
        'cvt'       => 'CVT',
    ];

    protected static array $fuelMap = [
        'petrol'   => 'Gasoline', 'gasoline' => 'Gasoline', 'gas' => 'Gasoline',
        'diesel'   => 'Diesel',
        'hybrid'   => 'Hybrid',
        'electric' => 'Electric',
    ];

    protected static array $driveMap = [
        'front' => 'FWD', 'fwd'  => 'FWD',
        'rear'  => 'RWD', 'rwd'  => 'RWD',
        'all'   => 'AWD', 'awd'  => 'AWD', '4wd' => '4WD', '4x4' => '4WD',
    ];

    protected static array $bodyTypeMap = [
        'sedan'       => 'Sedan',   'suv'         => 'SUV',
        'truck'       => 'Truck',   'coupe'       => 'Coupe',
        'hatchback'   => 'Hatchback', 'wagon'     => 'Wagon',
        'van'         => 'Van',     'convertible' => 'Convertible',
        'crossover'   => 'Crossover',
    ];

    protected static function mapValue(?string $value, array $map): ?string
    {
        if ($value === null || $value === '') return null;
        return $map[strtolower(trim($value))] ?? ucfirst(strtolower(trim($value)));
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function search(SearchQuery $query): array
    {
        try {
            $raw  = $this->fetchRaw($query);
            $lots = array_map([$this, 'normalize'], $raw);

            return $this->applyFilters($lots, $query);
        } catch (\Throwable $e) {
            $this->available = false;
            Log::error('['.get_class($this).'] '.$e->getMessage());

            return [];
        }
    }

    /** @param LotDTO[] $lots */
    protected function applyFilters(array $lots, SearchQuery $query): array
    {
        return array_values(array_filter($lots, function (LotDTO $lot) use ($query): bool {
            if ($query->make !== '' && !str_contains(strtolower($lot->make), strtolower($query->make))) {
                return false;
            }
            if ($query->model !== '') {
                $haystack = $lot->modelEn ?? $lot->model;
                if (!str_contains(strtolower($haystack), strtolower($query->model))) {
                    return false;
                }
            }
            if ($query->yearFrom > 0 && $lot->year < $query->yearFrom) {
                return false;
            }
            if ($query->yearTo > 0 && $lot->year > $query->yearTo) {
                return false;
            }
            if ($query->priceMax > 0 && $lot->price > $query->priceMax) {
                return false;
            }
            if ($query->priceMin > 0 && $lot->price < $query->priceMin) {
                return false;
            }
            if ($query->mileageMin > 0 && $lot->mileage < $query->mileageMin) {
                return false;
            }
            if ($query->mileageMax > 0 && $lot->mileage > $query->mileageMax) {
                return false;
            }
            if ($query->engineMin > 0 && ($lot->engineVolume === null || $lot->engineVolume < $query->engineMin)) {
                return false;
            }
            if ($query->engineMax > 0 && ($lot->engineVolume === null || $lot->engineVolume > $query->engineMax)) {
                return false;
            }
            if ($query->hasAccident !== null && $lot->hasAccident !== $query->hasAccident) {
                return false;
            }
            if ($query->floodHistory !== null && $lot->floodHistory !== $query->floodHistory) {
                return false;
            }
            if ($query->totalLossHistory !== null && $lot->totalLossHistory !== $query->totalLossHistory) {
                return false;
            }
            if ($query->insuranceCountMin !== null && ($lot->insuranceCount === null || $lot->insuranceCount < $query->insuranceCountMin)) {
                return false;
            }
            if ($query->insuranceCountMax !== null && ($lot->insuranceCount === null || $lot->insuranceCount > $query->insuranceCountMax)) {
                return false;
            }
            if ($query->ownersCountMin !== null && ($lot->ownersCount === null || $lot->ownersCount < $query->ownersCountMin)) {
                return false;
            }
            if ($query->ownersCountMax !== null && ($lot->ownersCount === null || $lot->ownersCount > $query->ownersCountMax)) {
                return false;
            }
            if ($query->repairCostMin > 0 && ($lot->repairCost === null || $lot->repairCost < $query->repairCostMin)) {
                return false;
            }
            if ($query->repairCostMax > 0 && ($lot->repairCost === null || $lot->repairCost > $query->repairCostMax)) {
                return false;
            }
            if ($query->retailValueMin > 0 && ($lot->retailValue === null || $lot->retailValue < $query->retailValueMin)) {
                return false;
            }
            if ($query->retailValueMax > 0 && ($lot->retailValue === null || $lot->retailValue > $query->retailValueMax)) {
                return false;
            }
            if ($query->seatCountMin > 0 && ($lot->seatCount === null || $lot->seatCount < $query->seatCountMin)) {
                return false;
            }
            if ($query->seatCountMax > 0 && ($lot->seatCount === null || $lot->seatCount > $query->seatCountMax)) {
                return false;
            }
            if ($query->registrationYearMonthMin > 0 && ($lot->registrationYearMonth === null || $lot->registrationYearMonth < $query->registrationYearMonthMin)) {
                return false;
            }
            if ($query->registrationYearMonthMax > 0 && ($lot->registrationYearMonth === null || $lot->registrationYearMonth > $query->registrationYearMonthMax)) {
                return false;
            }
            if (!empty($query->bodyTypes) && ($lot->bodyType === null || !in_array($lot->bodyType, $query->bodyTypes, true))) {
                return false;
            }
            if (!empty($query->transmissions) && ($lot->transmission === null || !in_array($lot->transmission, $query->transmissions, true))) {
                return false;
            }
            if (!empty($query->fuelTypes) && ($lot->fuel === null || !in_array($lot->fuel, $query->fuelTypes, true))) {
                return false;
            }
            if (!empty($query->driveTypes) && ($lot->driveType === null || !in_array($lot->driveType, $query->driveTypes, true))) {
                return false;
            }
            if (!empty($query->lienStatuses) && ($lot->lienStatus === null || !in_array($lot->lienStatus, $query->lienStatuses, true))) {
                return false;
            }
            if (!empty($query->seizureStatuses) && ($lot->seizureStatus === null || !in_array($lot->seizureStatus, $query->seizureStatuses, true))) {
                return false;
            }
            if (!empty($query->sellTypes) && ($lot->sellType === null || !in_array($lot->sellType, $query->sellTypes, true))) {
                return false;
            }
            if (!empty($query->colors)) {
                $lotColor = $lot->color ? strtolower(trim($lot->color)) : '';
                $match = false;
                foreach ($query->colors as $color) {
                    if (str_contains($lotColor, strtolower(trim($color)))) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) {
                    return false;
                }
            }
            if ($query->vin !== '' && ($lot->vin === null || !str_starts_with(strtoupper($lot->vin), strtoupper($query->vin)))) {
                return false;
            }

            return true;
        }));
    }
}
