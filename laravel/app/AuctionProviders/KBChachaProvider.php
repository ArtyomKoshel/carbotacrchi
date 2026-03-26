<?php

namespace App\AuctionProviders;

use App\Dto\LotDTO;
use App\Services\SearchQuery;

class KBChachaProvider extends AbstractProvider
{
    public function getKey(): string { return 'kbcha'; }
    public function getName(): string { return 'KBChacha'; }

    public function fetchRaw(SearchQuery $query): array
    {
        $file = config('auction.data_dir').'/mock_kbcha.json';
        if (!file_exists($file)) {
            throw new \RuntimeException('Mock file not found: '.$file);
        }

        return json_decode(file_get_contents($file), true) ?? [];
    }

    public function normalize(array $raw): LotDTO
    {
        $uid = md5($raw['carNm'] ?? uniqid());

        return new LotDTO(
            id:          'kbcha_'.$uid,
            source:      $this->getKey(),
            sourceName:  $this->getName(),
            make:        $raw['make']          ?? '',
            model:       $raw['model']         ?? '',
            year:        (int) ($raw['year']   ?? 0),
            price:       (int) ($raw['price']  ?? 0),
            mileage:     (int) ($raw['distance'] ?? 0),
            damage:      null,
            title:       'Clean',
            location:    $raw['location']      ?? 'Korea',
            lotUrl:      'https://www.kbchachacha.com/public/search/detail.kbc?carSeq='.$uid,
            imageUrl:    $raw['imgUrl']        ?? null,
            vin:         null,
            auctionDate: $raw['regDate']       ?? null,
            createdAt:   date('c'),
            transmission:    self::mapValue($raw['transmission'] ?? null, self::$transmissionMap),
            fuel:            self::mapValue($raw['fuel']         ?? null, self::$fuelMap),
            bodyType:        self::mapValue($raw['bodyType']     ?? null, self::$bodyTypeMap),
            driveType:       self::mapValue($raw['driveType']    ?? null, self::$driveMap),
            color:           isset($raw['color'])    ? ucfirst(strtolower(trim($raw['color'])))                : null,
            engineVolume:    isset($raw['engineCC']) ? round((float) $raw['engineCC'] / 1000, 1)               : null,
        );
    }
}
