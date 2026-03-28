<?php

namespace App\AuctionProviders;

use App\Dto\LotDTO;
use App\Services\SearchQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KBChachaProvider extends AbstractProvider
{
    public function getKey(): string { return 'kbcha'; }
    public function getName(): string { return 'KBChacha'; }

    public function fetchRaw(SearchQuery $query): array
    {
        if ($this->hasDbLots()) {
            return DB::table('lots')
                ->where('source', 'kbcha')
                ->where('is_active', true)
                ->get()
                ->map(fn ($row) => json_decode($row->raw_data, true))
                ->filter()
                ->values()
                ->toArray();
        }

        $file = config('auction.data_dir').'/mock_kbcha.json';
        if (!file_exists($file)) {
            throw new \RuntimeException('Mock file not found: '.$file);
        }

        return json_decode(file_get_contents($file), true) ?? [];
    }

    private function hasDbLots(): bool
    {
        try {
            if (!Schema::hasTable('lots')) {
                return false;
            }
            return DB::table('lots')
                ->where('source', 'kbcha')
                ->where('is_active', true)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function normalize(array $raw): LotDTO
    {
        $uid = $raw['carSeq'] ?? $raw['carId'] ?? md5($raw['carNm'] ?? uniqid());

        return new LotDTO(
            id:          'kbcha_'.$uid,
            source:      $this->getKey(),
            sourceName:  $this->getName(),
            make:        $raw['make']  ?? $raw['makerName']  ?? '',
            model:       $raw['model'] ?? $raw['modelName']  ?? '',
            year:        (int) ($raw['year'] ?? 0),
            price:       (int) ($raw['price'] ?? 0),
            mileage:     (int) ($raw['distance'] ?? $raw['mileage'] ?? 0),
            damage:      null,
            title:       'Clean',
            location:    $raw['location'] ?? $raw['regionName'] ?? 'Korea',
            lotUrl:      $raw['lot_url'] ?? 'https://www.kbchachacha.com/public/car/detail.kbc?carSeq='.$uid,
            imageUrl:    $raw['imgUrl']  ?? $raw['image_url'] ?? $raw['photoUrl'] ?? null,
            vin:         null,
            auctionDate: $raw['regDate'] ?? null,
            createdAt:   date('c'),
            transmission:    self::mapValue($raw['transmission'] ?? $raw['missionName'] ?? null, self::$transmissionMap),
            fuel:            self::mapValue($raw['fuel'] ?? $raw['fuelName'] ?? null, self::$fuelMap),
            bodyType:        self::mapValue($raw['bodyType'] ?? null, self::$bodyTypeMap),
            driveType:       self::mapValue($raw['driveType'] ?? null, self::$driveMap),
            color:           isset($raw['color']) ? ucfirst(strtolower(trim($raw['color']))) : (isset($raw['colorName']) ? ucfirst(strtolower(trim($raw['colorName']))) : null),
            engineVolume:    isset($raw['engineCC']) ? round((float) $raw['engineCC'] / 1000, 1) : ($raw['engine_volume'] ?? null),
            trim:            $raw['trim'] ?? $raw['gradeName'] ?? null,
        );
    }
}
