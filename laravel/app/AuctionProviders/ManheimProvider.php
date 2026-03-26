<?php

namespace App\AuctionProviders;

use App\Dto\LotDTO;
use App\Services\SearchQuery;

class ManheimProvider extends AbstractProvider
{
    public function getKey(): string { return 'manheim'; }
    public function getName(): string { return 'Manheim'; }

    public function fetchRaw(SearchQuery $query): array
    {
        $file = config('auction.data_dir').'/mock_manheim.json';
        if (!file_exists($file)) {
            throw new \RuntimeException('Mock file not found: '.$file);
        }

        return json_decode(file_get_contents($file), true) ?? [];
    }

    public function normalize(array $raw): LotDTO
    {
        return new LotDTO(
            id:          'manheim_'.($raw['id'] ?? uniqid()),
            source:      $this->getKey(),
            sourceName:  $this->getName(),
            make:        $raw['make']     ?? '',
            model:       $raw['model']    ?? '',
            year:        (int) ($raw['year']    ?? 0),
            price:       (int) ($raw['price']   ?? 0),
            mileage:     (int) ($raw['mileage'] ?? 0),
            damage:      null,
            title:       'Grade '.($raw['conditionGrade'] ?? 'N/A'),
            location:    $raw['location'] ?? '',
            lotUrl:      'https://www.manheim.com/members/psSearch/run?itemId='.($raw['id'] ?? ''),
            imageUrl:    $raw['imageUrl'] ?? null,
            vin:         $raw['vin']      ?? null,
            auctionDate: $raw['saleDate'] ?? null,
            createdAt:   date('c'),
            transmission:    self::mapValue($raw['transmission'] ?? null, self::$transmissionMap),
            fuel:            self::mapValue($raw['fuelType']     ?? null, self::$fuelMap),
            bodyType:        self::mapValue($raw['bodyStyle']    ?? null, self::$bodyTypeMap),
            driveType:       self::mapValue($raw['drivetrain']   ?? null, self::$driveMap),
            color:           isset($raw['exteriorColor']) ? ucfirst(strtolower(trim($raw['exteriorColor']))) : null,
            engineVolume:    isset($raw['displacement'])  ? (float) $raw['displacement']                     : null,
            cylinders:       isset($raw['cylinders'])     ? (int) $raw['cylinders']                          : null,
            trim:            $raw['trim']                 ?? null,
        );
    }
}
