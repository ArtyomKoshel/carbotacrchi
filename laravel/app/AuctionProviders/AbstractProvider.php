<?php

namespace App\AuctionProviders;

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
}
