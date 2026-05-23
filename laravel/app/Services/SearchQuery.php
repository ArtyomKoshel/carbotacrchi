<?php

namespace App\Services;

use App\Models\BotFilterSetting;

class SearchQuery
{
    public string $make = '';
    public string $model = '';
    public string $generation = '';
    public int $yearFrom = 0;
    public int $yearTo = 0;
    public int $priceMax = 0;
    public int $priceMin = 0;
    public int $mileageMin = 0;
    public int $mileageMax = 0;
    public float $engineMin = 0;
    public float $engineMax = 0;

    public ?int $insuranceCountMin = null;
    public ?int $insuranceCountMax = null;
    public ?int $ownersCountMin = null;
    public ?int $ownersCountMax = null;
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

    public string $trim = '';
    public string $vin = '';
    /** @var string[] */
    public array $sources = ['encar'];
    public string $sort = 'date';
    public int $limit = 20;
    public int $offset = 0;

    public static function fromArray(array $data): self
    {
        $q = new self();

        $q->make = trim((string) ($data['make'] ?? ''));
        $q->model = trim((string) ($data['model'] ?? ''));
        $q->generation = trim((string) ($data['generation'] ?? ''));
        $q->yearFrom = (int) ($data['yearFrom'] ?? 0);
        $q->yearTo = (int) ($data['yearTo'] ?? 0);
        $q->priceMax = (int) ($data['priceMax'] ?? 0);
        $q->priceMin = (int) ($data['priceMin'] ?? 0);
        $q->mileageMin = (int) ($data['mileageMin'] ?? 0);
        $q->mileageMax = (int) ($data['mileageMax'] ?? 0);
        $q->engineMin = (float) ($data['engineMin'] ?? 0);
        $q->engineMax = (float) ($data['engineMax'] ?? 0);

        $q->insuranceCountMin = self::toNullableInt($data['insuranceCountMin'] ?? null);
        $q->insuranceCountMax = self::toNullableInt($data['insuranceCountMax'] ?? null);
        $q->ownersCountMin = self::toNullableInt($data['ownersCountMin'] ?? null);
        $q->ownersCountMax = self::toNullableInt($data['ownersCountMax'] ?? null);
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

        $q->trim = trim((string) ($data['trim'] ?? ''));
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
        if (!config('search_tolerance.enabled', true)) {
            return $this;
        }

        $settings = BotFilterSetting::allEnabled();
        if (empty($settings)) {
            return $this;
        }

        $clone = clone $this;

        foreach ($settings as $setting) {
            if (!in_array($setting->dtype, ['int', 'float', 'date'], true)) {
                continue;
            }
            if (!in_array($setting->tolerance_type, ['absolute', 'percentage'], true)) {
                continue;
            }
            if ($setting->tolerance_value === null) {
                continue;
            }

            $rangeProps = self::getRangeProps((string) $setting->field_name, $clone);
            if ($rangeProps === null) {
                continue;
            }
            [$minProp, $maxProp] = $rangeProps;

            $tolerance = [
                'type' => (string) $setting->tolerance_type,
                'value' => (float) $setting->tolerance_value,
            ];
            $isFloat = $setting->dtype === 'float';

            // When only one bound is set, tolerance creates a range around that value.
            // Save originals first to avoid double-applying tolerance.
            $hasMin  = $clone->$minProp !== null && $clone->$minProp > 0;
            $hasMax  = $clone->$maxProp !== null && $clone->$maxProp > 0;
            $origMin = $clone->$minProp;
            $origMax = $clone->$maxProp;

            if (!$hasMin && !$hasMax) {
                continue;
            }

            if ($hasMin && !$hasMax) {
                // "пробег 100 000" → range [100 000-tol, 100 000+tol]
                $clone->$minProp = self::applyTolerance($origMin, $tolerance, true,  $isFloat);
                $clone->$maxProp = self::applyTolerance($origMin, $tolerance, false, $isFloat);
            } elseif (!$hasMin && $hasMax) {
                // "пробег до 100 000" → range [100 000-tol, 100 000+tol]
                $clone->$minProp = self::applyTolerance($origMax, $tolerance, true,  $isFloat);
                $clone->$maxProp = self::applyTolerance($origMax, $tolerance, false, $isFloat);
            } else {
                // Both bounds set: widen the range outward
                $clone->$minProp = self::applyTolerance($origMin, $tolerance, true,  $isFloat);
                $clone->$maxProp = self::applyTolerance($origMax, $tolerance, false, $isFloat);
            }
        }

        return $clone;
    }

    /** @return array{0: string, 1: string}|null */
    private static function getRangeProps(string $fieldName, self $query): ?array
    {
        $candidates = self::buildRangePropCandidates($fieldName);
        foreach ($candidates as [$minProp, $maxProp]) {
            if (property_exists($query, $minProp) && property_exists($query, $maxProp)) {
                return [$minProp, $maxProp];
            }
        }

        return null;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private static function buildRangePropCandidates(string $fieldName): array
    {
        $parts = array_values(array_filter(explode('_', trim($fieldName)), static fn (string $part) => $part !== ''));
        if ($parts === []) {
            return [];
        }

        $candidates = [];
        for ($length = count($parts); $length >= 1; $length--) {
            $base = self::snakeToCamel(implode('_', array_slice($parts, 0, $length)));
            $candidates[] = [$base . 'Min', $base . 'Max'];
            $candidates[] = [$base . 'From', $base . 'To'];
        }

        $unique = [];
        $seen = [];
        foreach ($candidates as [$minProp, $maxProp]) {
            $key = $minProp . '|' . $maxProp;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = [$minProp, $maxProp];
        }

        return $unique;
    }

    private static function snakeToCamel(string $value): string
    {
        return lcfirst(str_replace('_', '', ucwords($value, '_')));
    }

    public function describeForChat(): string
    {
        $parts = [];
        if ($this->make)       $parts[] = $this->make;
        if ($this->model)      $parts[] = $this->model;
        if ($this->generation) $parts[] = 'поколение: ' . $this->generation;
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
        if ($this->insuranceCountMin !== null) $parts[] = "страховых от {$this->insuranceCountMin}";
        if ($this->insuranceCountMax !== null) $parts[] = "страховых до {$this->insuranceCountMax}";
        if ($this->ownersCountMin !== null) $parts[] = "владельцев от {$this->ownersCountMin}";
        if ($this->ownersCountMax !== null) $parts[] = "владельцев до {$this->ownersCountMax}";
        if ($this->hasAccident !== null) $parts[] = $this->hasAccident ? 'с ДТП' : 'без ДТП';
        if ($this->floodHistory !== null) $parts[] = $this->floodHistory ? 'с затоплением' : 'без затоплений';
        if ($this->lienStatuses) $parts[] = 'залог: ' . implode('/', $this->lienStatuses);
        if ($this->seizureStatuses) $parts[] = 'арест: ' . implode('/', $this->seizureStatuses);
        if ($this->fuelTypes)  $parts[] = implode('/', $this->fuelTypes);
        if ($this->transmissions) $parts[] = implode('/', $this->transmissions);
        if ($this->driveTypes) $parts[] = implode('/', $this->driveTypes);
        if ($this->bodyTypes)  $parts[] = implode('/', $this->bodyTypes);
        if ($this->colors) $parts[] = 'цвет: ' . implode('/', $this->colors);
        if ($this->trim)   $parts[] = 'комплектация: ' . $this->trim;

        return implode(', ', $parts) ?: 'Все лоты';
    }

    public function toSearchArray(): array
    {
        $data = [];
        if ($this->make)          $data['make']          = $this->make;
        if ($this->model)         $data['model']         = $this->model;
        if ($this->generation)    $data['generation']    = $this->generation;
        if ($this->yearFrom)      $data['yearFrom']      = $this->yearFrom;
        if ($this->yearTo)        $data['yearTo']        = $this->yearTo;
        if ($this->priceMin)      $data['priceMin']      = $this->priceMin;
        if ($this->priceMax)      $data['priceMax']      = $this->priceMax;
        if ($this->mileageMin)    $data['mileageMin']    = $this->mileageMin;
        if ($this->mileageMax)    $data['mileageMax']    = $this->mileageMax;
        if ($this->engineMin)     $data['engineMin']     = $this->engineMin;
        if ($this->engineMax)     $data['engineMax']     = $this->engineMax;
        if ($this->insuranceCountMin !== null) $data['insuranceCountMin'] = $this->insuranceCountMin;
        if ($this->insuranceCountMax !== null) $data['insuranceCountMax'] = $this->insuranceCountMax;
        if ($this->ownersCountMin !== null) $data['ownersCountMin'] = $this->ownersCountMin;
        if ($this->ownersCountMax !== null) $data['ownersCountMax'] = $this->ownersCountMax;
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
        if ($this->trim)          $data['trim']          = $this->trim;
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
            $step = 10.0;
            $result = $isMin
                ? floor($result * $step) / $step
                : ceil($result * $step) / $step;

            return round($result, 1);
        }

        return (int) round($result);
    }

    private static function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || is_string($value)) {
            return is_numeric($value) ? (int) $value : null;
        }

        return null;
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
