<?php

namespace App\Services;

use App\Models\BotFilterSetting;

class SearchQuery
{
    public string $make = '';
    public string $model = '';
    public int $yearFrom = 0;
    public int $yearTo = 0;
    public int $priceMax = 0;
    public int $priceMin = 0;
    public int $mileageMin = 0;
    public int $mileageMax = 0;
    public float $engineMin = 0;
    public float $engineMax = 0;

    public int $insuranceCountMin = 0;
    public int $insuranceCountMax = 0;
    public int $ownersCountMin = 0;
    public int $ownersCountMax = 0;
    public ?bool $hasAccident = null;
    public ?bool $floodHistory = null;
    public ?bool $totalLossHistory = null;

    public int $repairCostMin = 0;
    public int $repairCostMax = 0;
    public int $retailValueMin = 0;
    public int $retailValueMax = 0;

    public int $seatCountMin = 0;
    public int $seatCountMax = 0;
    public int $registrationYearMonthMin = 0;
    public int $registrationYearMonthMax = 0;

    /** @var string[] */
    public array $bodyTypes = [];
    /** @var string[] */
    public array $transmissions = [];
    /** @var string[] */
    public array $fuelTypes = [];
    /** @var string[] */
    public array $driveTypes = [];
    /** @var string[] */
    public array $lienStatuses = [];
    /** @var string[] */
    public array $seizureStatuses = [];
    /** @var string[] */
    public array $sellTypes = [];
    /** @var string[] */
    public array $colors = [];

    public string $vin = '';
    /** @var string[] */
    public array $sources = ['encar', 'kbcha'];
    public string $sort = 'date';
    public int $limit = 20;
    public int $offset = 0;

    public static function fromArray(array $data): self
    {
        $q = new self();

        $q->make = trim((string) ($data['make'] ?? ''));
        $q->model = trim((string) ($data['model'] ?? ''));
        $q->yearFrom = (int) ($data['yearFrom'] ?? 0);
        $q->yearTo = (int) ($data['yearTo'] ?? 0);
        $q->priceMax = (int) ($data['priceMax'] ?? 0);
        $q->priceMin = (int) ($data['priceMin'] ?? 0);
        $q->mileageMin = (int) ($data['mileageMin'] ?? 0);
        $q->mileageMax = (int) ($data['mileageMax'] ?? 0);
        $q->engineMin = (float) ($data['engineMin'] ?? 0);
        $q->engineMax = (float) ($data['engineMax'] ?? 0);

        $q->insuranceCountMin = (int) ($data['insuranceCountMin'] ?? 0);
        $q->insuranceCountMax = (int) ($data['insuranceCountMax'] ?? 0);
        $q->ownersCountMin = (int) ($data['ownersCountMin'] ?? 0);
        $q->ownersCountMax = (int) ($data['ownersCountMax'] ?? 0);
        $q->repairCostMin = (int) ($data['repairCostMin'] ?? 0);
        $q->repairCostMax = (int) ($data['repairCostMax'] ?? 0);
        $q->retailValueMin = (int) ($data['retailValueMin'] ?? 0);
        $q->retailValueMax = (int) ($data['retailValueMax'] ?? 0);
        $q->seatCountMin = (int) ($data['seatCountMin'] ?? 0);
        $q->seatCountMax = (int) ($data['seatCountMax'] ?? 0);
        $q->registrationYearMonthMin = (int) ($data['registrationYearMonthMin'] ?? 0);
        $q->registrationYearMonthMax = (int) ($data['registrationYearMonthMax'] ?? 0);

        $q->hasAccident = self::toNullableBool($data['hasAccident'] ?? null);
        $q->floodHistory = self::toNullableBool($data['floodHistory'] ?? null);
        $q->totalLossHistory = self::toNullableBool($data['totalLossHistory'] ?? null);

        $q->vin = trim((string) ($data['vin'] ?? ''));

        foreach (['bodyTypes', 'transmissions', 'fuelTypes', 'driveTypes', 'lienStatuses', 'seizureStatuses', 'sellTypes', 'colors'] as $key) {
            if (!empty($data[$key]) && is_array($data[$key])) {
                $q->$key = array_map('strval', $data[$key]);
            }
        }

        $q->sort = in_array($data['sort'] ?? '', ['date', 'price_asc', 'price_desc'], true)
            ? $data['sort']
            : 'date';
        $q->limit = min((int) ($data['limit'] ?? 20), 100);
        $q->offset = max((int) ($data['offset'] ?? 0), 0);

        if (!empty($data['sources']) && is_array($data['sources'])) {
            $q->sources = array_map('strval', $data['sources']);
        }

        return $q;
    }

    public function withBotTolerance(): self
    {
        $tolerances = BotFilterSetting::getTolerances();

        if (empty($tolerances)) {
            return $this;
        }

        $clone = clone $this;

        $rangeFields = [
            'mileage' => ['mileageMin', 'mileageMax', 'int'],
            'price' => ['priceMin', 'priceMax', 'int'],
            'engine_volume' => ['engineMin', 'engineMax', 'float'],
            'year' => ['yearFrom', 'yearTo', 'int'],
            'insurance_count' => ['insuranceCountMin', 'insuranceCountMax', 'int'],
            'owners_count' => ['ownersCountMin', 'ownersCountMax', 'int'],
            'repair_cost' => ['repairCostMin', 'repairCostMax', 'int'],
            'retail_value' => ['retailValueMin', 'retailValueMax', 'int'],
            'seat_count' => ['seatCountMin', 'seatCountMax', 'int'],
            'registration_year_month' => ['registrationYearMonthMin', 'registrationYearMonthMax', 'int'],
        ];

        foreach ($rangeFields as $fieldName => [$minProp, $maxProp, $type]) {
            if (!isset($tolerances[$fieldName])) {
                continue;
            }

            $tolerance = $tolerances[$fieldName];
            $isFloat = $type === 'float';

            if ($clone->$minProp > 0) {
                $clone->$minProp = self::applyTolerance($clone->$minProp, $tolerance, true, $isFloat);
            }

            if ($clone->$maxProp > 0) {
                $clone->$maxProp = self::applyTolerance($clone->$maxProp, $tolerance, false, $isFloat);
            }
        }

        return $clone;
    }

    public function withTolerance(): self
    {
        $config = config('search_tolerance');
        if (!$config['enabled']) {
            return $this;
        }

        $clone = clone $this;
        $t     = $config['tolerances'];

        if ($clone->mileageMin > 0) {
            $clone->mileageMin = (int) round($clone->mileageMin * (1 - $t['mileage']));
        }
        if ($clone->mileageMax > 0) {
            $clone->mileageMax = (int) round($clone->mileageMax * (1 + $t['mileage']));
        }

        if ($clone->priceMin > 0) {
            $clone->priceMin = (int) round($clone->priceMin * (1 - $t['price']));
        }
        if ($clone->priceMax > 0) {
            $clone->priceMax = (int) round($clone->priceMax * (1 + $t['price']));
        }

        if ($clone->engineMin > 0) {
            $clone->engineMin = round($clone->engineMin * (1 - $t['engine']), 1);
        }
        if ($clone->engineMax > 0) {
            $clone->engineMax = round($clone->engineMax * (1 + $t['engine']), 1);
        }

        if ($clone->yearFrom > 0) {
            $clone->yearFrom -= $t['year'];
        }
        if ($clone->yearTo > 0) {
            $clone->yearTo += $t['year'];
        }

        return $clone;
    }

    public function describeForChat(): string
    {
        $parts = [];
        if ($this->make)       $parts[] = $this->make;
        if ($this->model)      $parts[] = $this->model;
        if ($this->yearFrom && $this->yearTo) {
            $parts[] = "{$this->yearFrom}–{$this->yearTo}";
        } elseif ($this->yearFrom) {
            $parts[] = "от {$this->yearFrom} г.";
        } elseif ($this->yearTo) {
            $parts[] = "до {$this->yearTo} г.";
        }
        if ($this->priceMax)   $parts[] = "до \${$this->priceMax}";
        if ($this->priceMin)   $parts[] = "от \${$this->priceMin}";
        if ($this->mileageMin) $parts[] = "пробег от " . number_format($this->mileageMin) . " км";
        if ($this->mileageMax) $parts[] = "пробег до " . number_format($this->mileageMax) . " км";
        if ($this->engineMin)  $parts[] = "двигатель от {$this->engineMin} л";
        if ($this->engineMax)  $parts[] = "двигатель до {$this->engineMax} л";
        if ($this->insuranceCountMin) $parts[] = "страховых от {$this->insuranceCountMin}";
        if ($this->insuranceCountMax) $parts[] = "страховых до {$this->insuranceCountMax}";
        if ($this->ownersCountMin) $parts[] = "владельцев от {$this->ownersCountMin}";
        if ($this->ownersCountMax) $parts[] = "владельцев до {$this->ownersCountMax}";
        if ($this->hasAccident !== null) $parts[] = $this->hasAccident ? 'с ДТП' : 'без ДТП';
        if ($this->floodHistory !== null) $parts[] = $this->floodHistory ? 'с затоплением' : 'без затоплений';
        if ($this->lienStatuses) $parts[] = 'залог: ' . implode('/', $this->lienStatuses);
        if ($this->seizureStatuses) $parts[] = 'арест: ' . implode('/', $this->seizureStatuses);
        if ($this->fuelTypes)  $parts[] = implode('/', $this->fuelTypes);
        if ($this->transmissions) $parts[] = implode('/', $this->transmissions);
        if ($this->driveTypes) $parts[] = implode('/', $this->driveTypes);
        if ($this->bodyTypes)  $parts[] = implode('/', $this->bodyTypes);
        if ($this->colors) $parts[] = 'цвет: ' . implode('/', $this->colors);

        return implode(', ', $parts) ?: 'Все лоты';
    }

    public function toSearchArray(): array
    {
        $data = [];
        if ($this->make)          $data['make']          = $this->make;
        if ($this->model)         $data['model']         = $this->model;
        if ($this->yearFrom)      $data['yearFrom']      = $this->yearFrom;
        if ($this->yearTo)        $data['yearTo']        = $this->yearTo;
        if ($this->priceMin)      $data['priceMin']      = $this->priceMin;
        if ($this->priceMax)      $data['priceMax']      = $this->priceMax;
        if ($this->mileageMin)    $data['mileageMin']    = $this->mileageMin;
        if ($this->mileageMax)    $data['mileageMax']    = $this->mileageMax;
        if ($this->engineMin)     $data['engineMin']     = $this->engineMin;
        if ($this->engineMax)     $data['engineMax']     = $this->engineMax;
        if ($this->insuranceCountMin) $data['insuranceCountMin'] = $this->insuranceCountMin;
        if ($this->insuranceCountMax) $data['insuranceCountMax'] = $this->insuranceCountMax;
        if ($this->ownersCountMin) $data['ownersCountMin'] = $this->ownersCountMin;
        if ($this->ownersCountMax) $data['ownersCountMax'] = $this->ownersCountMax;
        if ($this->repairCostMin) $data['repairCostMin'] = $this->repairCostMin;
        if ($this->repairCostMax) $data['repairCostMax'] = $this->repairCostMax;
        if ($this->retailValueMin) $data['retailValueMin'] = $this->retailValueMin;
        if ($this->retailValueMax) $data['retailValueMax'] = $this->retailValueMax;
        if ($this->seatCountMin) $data['seatCountMin'] = $this->seatCountMin;
        if ($this->seatCountMax) $data['seatCountMax'] = $this->seatCountMax;
        if ($this->registrationYearMonthMin) $data['registrationYearMonthMin'] = $this->registrationYearMonthMin;
        if ($this->registrationYearMonthMax) $data['registrationYearMonthMax'] = $this->registrationYearMonthMax;
        if ($this->hasAccident !== null) $data['hasAccident'] = $this->hasAccident;
        if ($this->floodHistory !== null) $data['floodHistory'] = $this->floodHistory;
        if ($this->totalLossHistory !== null) $data['totalLossHistory'] = $this->totalLossHistory;
        if ($this->fuelTypes)     $data['fuelTypes']     = $this->fuelTypes;
        if ($this->transmissions) $data['transmissions'] = $this->transmissions;
        if ($this->bodyTypes)     $data['bodyTypes']     = $this->bodyTypes;
        if ($this->driveTypes)    $data['driveTypes']    = $this->driveTypes;
        if ($this->lienStatuses)  $data['lienStatuses']  = $this->lienStatuses;
        if ($this->seizureStatuses) $data['seizureStatuses'] = $this->seizureStatuses;
        if ($this->sellTypes)     $data['sellTypes']     = $this->sellTypes;
        if ($this->colors)        $data['colors']        = $this->colors;
        if ($this->vin)           $data['vin']           = $this->vin;
        if ($this->sources !== ['encar', 'kbcha']) {
            $data['sources'] = $this->sources;
        }
        return $data;
    }

    /** @param array{type: string, value: float} $tolerance */
    private static function applyTolerance(int|float $value, array $tolerance, bool $isMin, bool $isFloat): int|float
    {
        $type = $tolerance['type'] ?? 'none';
        $delta = max(0, (float) ($tolerance['value'] ?? 0));
        if ($type === 'percentage' && $delta > 1) {
            $delta /= 100;
        }

        if ($type === 'absolute') {
            $result = $isMin ? ($value - $delta) : ($value + $delta);
        } elseif ($type === 'percentage') {
            $result = $isMin ? ($value * (1 - $delta)) : ($value * (1 + $delta));
        } else {
            $result = $value;
        }

        $result = max(0, $result);

        if ($isFloat) {
            return round($result, 2);
        }

        return (int) round($result);
    }

    private static function toNullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'y'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'n'], true)) {
                return false;
            }
        }

        return null;
    }
}
