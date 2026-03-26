<?php

namespace App\AuctionProviders;

use App\Dto\LotDTO;
use App\Services\SearchQuery;

class CopartProvider extends AbstractProvider
{
    public function getKey(): string { return 'copart'; }
    public function getName(): string { return 'Copart'; }

    public function fetchRaw(SearchQuery $query): array
    {
        $file = config('auction.data_dir').'/mock_copart.json';
        if (!file_exists($file)) {
            throw new \RuntimeException('Mock file not found: '.$file);
        }

        return json_decode(file_get_contents($file), true) ?? [];
    }

    public function normalize(array $raw): LotDTO
    {
        return new LotDTO(
            id:          'copart_'.($raw['lot_number'] ?? uniqid()),
            source:      $this->getKey(),
            sourceName:  $this->getName(),
            make:        $raw['make']                ?? '',
            model:       $raw['model']               ?? '',
            year:        (int) ($raw['year']          ?? 0),
            price:       (int) ($raw['buy_now_price'] ?? 0),
            mileage:     (int) ($raw['odometer']      ?? 0),
            damage:      $raw['damage_description']   ?? null,
            title:       $raw['title_type']           ?? '',
            location:    $raw['location']             ?? '',
            lotUrl:      'https://copart.com/lot/'.($raw['lot_number'] ?? ''),
            imageUrl:    $raw['image_url']            ?? null,
            vin:         $raw['vin']                  ?? null,
            auctionDate: $raw['auction_date']         ?? null,
            createdAt:   date('c'),
            transmission:    self::mapValue($raw['transmission']    ?? null, self::$transmissionMap),
            fuel:            self::mapValue($raw['fuel']            ?? null, self::$fuelMap),
            bodyType:        self::mapValue($raw['body_type']       ?? null, self::$bodyTypeMap),
            driveType:       self::mapValue($raw['drive_type']      ?? null, self::$driveMap),
            color:           isset($raw['color'])          ? ucfirst(strtolower(trim($raw['color'])))           : null,
            engineVolume:    isset($raw['engine_volume'])  ? (float) $raw['engine_volume']                      : null,
            cylinders:       isset($raw['cylinders'])      ? (int) $raw['cylinders']                            : null,
            hasKeys:         isset($raw['has_keys'])       ? ($raw['has_keys'] === '1' || $raw['has_keys'] === 1 || $raw['has_keys'] === true) : null,
            secondaryDamage: $raw['secondary_damage']     ?? null,
            document:        $raw['document']             ?? null,
            retailValue:     isset($raw['retail_value'])  ? (int) (float) $raw['retail_value']                 : null,
            repairCost:      isset($raw['repair_cost'])   ? (int) (float) $raw['repair_cost']                  : null,
            trim:            $raw['trim']                 ?? null,
        );
    }
}
