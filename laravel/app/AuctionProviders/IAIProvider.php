<?php

namespace App\AuctionProviders;

use App\Dto\LotDTO;
use App\Services\SearchQuery;

class IAIProvider extends AbstractProvider
{
    public function getKey(): string { return 'iai'; }
    public function getName(): string { return 'IAAI'; }

    public function fetchRaw(SearchQuery $query): array
    {
        $file = config('auction.data_dir').'/mock_iai.json';
        if (!file_exists($file)) {
            throw new \RuntimeException('Mock file not found: '.$file);
        }

        return json_decode(file_get_contents($file), true) ?? [];
    }

    public function normalize(array $raw): LotDTO
    {
        $location = trim(($raw['city'] ?? '').', '.($raw['state'] ?? ''), ', ');

        return new LotDTO(
            id:          'iai_'.($raw['lotNumber'] ?? uniqid()),
            source:      $this->getKey(),
            sourceName:  $this->getName(),
            make:        $raw['make']          ?? '',
            model:       $raw['model']         ?? '',
            year:        (int) ($raw['year']   ?? 0),
            price:       (int) ($raw['salePrice'] ?? 0),
            mileage:     (int) ($raw['miles']  ?? 0),
            damage:      $raw['primaryDamage'] ?? null,
            title:       $raw['titleType']     ?? '',
            location:    $location,
            lotUrl:      'https://www.iaai.com/vehi/'.($raw['lotNumber'] ?? ''),
            imageUrl:    $raw['imgUrl']        ?? null,
            vin:         $raw['vin']           ?? null,
            auctionDate: $raw['saleDate']      ?? null,
            createdAt:   date('c'),
            transmission:    self::mapValue($raw['transmission']   ?? null, self::$transmissionMap),
            fuel:            self::mapValue($raw['fuelType']       ?? null, self::$fuelMap),
            bodyType:        self::mapValue($raw['bodyType']       ?? null, self::$bodyTypeMap),
            driveType:       self::mapValue($raw['drivelineType']  ?? null, self::$driveMap),
            color:           isset($raw['color'])         ? ucfirst(strtolower(trim($raw['color'])))          : null,
            engineVolume:    isset($raw['engine'])        ? (float) $raw['engine']                            : null,
            cylinders:       isset($raw['cylinders'])     ? (int) $raw['cylinders']                           : null,
            hasKeys:         isset($raw['hasKeys'])       ? ($raw['hasKeys'] === '1' || $raw['hasKeys'] === 1) : null,
            secondaryDamage: $raw['secondaryDamage']      ?? null,
            document:        $raw['document']             ?? null,
            retailValue:     isset($raw['retailValue'])   ? (int) (float) $raw['retailValue']                 : null,
            repairCost:      isset($raw['repairCost'])    ? (int) (float) $raw['repairCost']                  : null,
            trim:            $raw['trim']                 ?? null,
        );
    }
}
