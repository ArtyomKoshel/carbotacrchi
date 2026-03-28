<?php

namespace App\Services;

class SearchQuery
{
    public string $make     = '';
    public string $model    = '';
    public int    $yearFrom = 0;
    public int    $yearTo   = 0;
    public int    $priceMax       = 0;
    public int    $priceMin       = 0;
    public int    $mileageMin     = 0;
    public int    $mileageMax     = 0;
    public float  $engineMin      = 0;
    public float  $engineMax      = 0;
    /** @var string[] */
    public array  $damageTypes    = [];
    /** @var string[] */
    public array  $titleTypes     = [];
    /** @var string[] */
    public array  $bodyTypes      = [];
    /** @var string[] */
    public array  $transmissions  = [];
    /** @var string[] */
    public array  $fuelTypes      = [];
    /** @var string[] */
    public array  $driveTypes     = [];
    public string $vin            = '';
    /** @var string[] */
    public array  $sources        = ['copart', 'iai', 'manheim', 'encar', 'kbcha'];
    public string $sort     = 'date';
    public int    $limit    = 20;
    public int    $offset   = 0;

    public static function fromArray(array $data): self
    {
        $q           = new self();
        $q->make     = trim((string) ($data['make']     ?? ''));
        $q->model    = trim((string) ($data['model']    ?? ''));
        $q->yearFrom = (int) ($data['yearFrom'] ?? 0);
        $q->yearTo   = (int) ($data['yearTo']   ?? 0);
        $q->priceMax   = (int)   ($data['priceMax']   ?? 0);
        $q->priceMin   = (int)   ($data['priceMin']   ?? 0);
        $q->mileageMin = (int)   ($data['mileageMin'] ?? 0);
        $q->mileageMax = (int)   ($data['mileageMax'] ?? 0);
        $q->engineMin  = (float) ($data['engineMin']  ?? 0);
        $q->engineMax  = (float) ($data['engineMax']  ?? 0);
        $q->vin        = trim((string) ($data['vin']  ?? ''));
        foreach (['damageTypes','titleTypes','bodyTypes','transmissions','fuelTypes','driveTypes'] as $key) {
            if (!empty($data[$key]) && is_array($data[$key])) {
                $q->$key = array_map('strval', $data[$key]);
            }
        }
        $q->sort     = in_array($data['sort'] ?? '', ['date', 'price_asc', 'price_desc'], true)
                       ? $data['sort']
                       : 'date';
        $q->limit    = min((int) ($data['limit']  ?? 20), 100);
        $q->offset   = max((int) ($data['offset'] ?? 0), 0);

        if (!empty($data['sources']) && is_array($data['sources'])) {
            $q->sources = array_map('strval', $data['sources']);
        }

        return $q;
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
        if ($this->fuelTypes)  $parts[] = implode('/', $this->fuelTypes);
        if ($this->transmissions) $parts[] = implode('/', $this->transmissions);
        if ($this->driveTypes) $parts[] = implode('/', $this->driveTypes);
        if ($this->bodyTypes)  $parts[] = implode('/', $this->bodyTypes);

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
        if ($this->fuelTypes)     $data['fuelTypes']     = $this->fuelTypes;
        if ($this->transmissions) $data['transmissions'] = $this->transmissions;
        if ($this->bodyTypes)     $data['bodyTypes']     = $this->bodyTypes;
        if ($this->driveTypes)    $data['driveTypes']    = $this->driveTypes;
        if ($this->damageTypes)   $data['damageTypes']   = $this->damageTypes;
        if ($this->titleTypes)    $data['titleTypes']    = $this->titleTypes;
        if ($this->vin)           $data['vin']           = $this->vin;
        if ($this->sources !== ['copart', 'iai', 'manheim', 'encar', 'kbcha']) {
            $data['sources'] = $this->sources;
        }
        return $data;
    }
}
