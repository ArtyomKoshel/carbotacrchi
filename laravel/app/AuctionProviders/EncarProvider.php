<?php

namespace App\AuctionProviders;

use App\Dto\LotDTO;
use App\Services\SearchQuery;

class EncarProvider extends AbstractProvider
{
    public function getKey(): string { return 'encar'; }
    public function getName(): string { return 'Encar'; }

    public function fetchRaw(SearchQuery $query): array
    {
        $file = config('auction.data_dir').'/mock_encar.json';
        if (!file_exists($file)) {
            throw new \RuntimeException('Mock file not found: '.$file);
        }

        return json_decode(file_get_contents($file), true) ?? [];
    }

    public function normalize(array $raw): LotDTO
    {
        return new LotDTO(
            id:          'encar_'.($raw['carId'] ?? uniqid()),
            source:      $this->getKey(),
            sourceName:  $this->getName(),
            make:        $raw['make']             ?? '',
            model:       $raw['model']            ?? '',
            year:        (int) ($raw['year']       ?? 0),
            price:       (int) ($raw['price']      ?? 0),
            mileage:     (int) ($raw['mileage_km'] ?? 0),
            damage:      null,
            title:       'Clean',
            location:    $raw['location']         ?? 'Korea',
            lotUrl:      'https://www.encar.com/dc/dc_cardetailview.do?carid='.($raw['carId'] ?? ''),
            imageUrl:    $raw['imageUrl']         ?? null,
            vin:         $raw['vin']              ?? null,
            auctionDate: $raw['registrationDate'] ?? null,
            createdAt:   date('c'),
            transmission:    self::mapValue($raw['transmission'] ?? null, self::$transmissionMap),
            fuel:            self::mapValue($raw['fuel']         ?? null, self::$fuelMap),
            bodyType:        self::mapValue($raw['bodyType']     ?? null, self::$bodyTypeMap),
            driveType:       self::mapValue($raw['driveType']    ?? null, self::$driveMap),
            color:           isset($raw['color'])     ? ucfirst(strtolower(trim($raw['color'])))               : null,
            engineVolume:    isset($raw['engineCC'])  ? round((float) $raw['engineCC'] / 1000, 1)              : null,
            cylinders:       isset($raw['cylinders']) ? (int) $raw['cylinders']                                 : null,
            trim:            $raw['trim']             ?? null,
        );
    }
}
