<?php

namespace App\AuctionProviders;

use App\Dto\LotDTO;
use App\Services\SearchQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EncarProvider extends AbstractProvider
{
    public function getKey(): string { return 'encar'; }
    public function getName(): string { return 'Encar'; }

    public function fetchRaw(SearchQuery $query): array
    {
        if ($this->hasDbLots()) {
            return DB::table('lots')
                ->where('source', 'encar')
                ->where('is_active', true)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->values()
                ->toArray();
        }

        $file = config('auction.data_dir').'/mock_encar.json';
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
                ->where('source', 'encar')
                ->where('is_active', true)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function normalize(array $raw): LotDTO
    {
        if (isset($raw['id'])) {
            return new LotDTO(
                id:           $raw['id'],
                source:       $this->getKey(),
                sourceName:   $this->getName(),
                make:         $raw['make']    ?? '',
                model:        $raw['model']   ?? '',
                year:         (int) ($raw['year']    ?? 0),
                price:        (int) ($raw['price']   ?? 0),
                mileage:      (int) ($raw['mileage'] ?? 0),
                damage:       $raw['damage']   ?? null,
                title:        $raw['title']    ?? 'Clean',
                location:     $raw['location'] ?? 'Korea',
                lotUrl:       $raw['lot_url']   ?? '',
                imageUrl:     $raw['image_url'] ?? null,
                vin:          $raw['vin']       ?? null,
                auctionDate:  $raw['registration_date'] ?? null,
                createdAt:    $raw['created_at'] ?? date('c'),
                transmission: $raw['transmission']  ?? null,
                fuel:         $raw['fuel']          ?? null,
                bodyType:     $raw['body_type']     ?? null,
                driveType:    $raw['drive_type']    ?? null,
                color:        $raw['color']         ?? null,
                engineVolume: isset($raw['engine_volume']) ? (float) $raw['engine_volume'] : null,
                trim:         $raw['trim']          ?? null,
                hasAccident:      isset($raw['has_accident'])      ? (bool) $raw['has_accident']      : null,
                floodHistory:     isset($raw['flood_history'])     ? (bool) $raw['flood_history']     : null,
                totalLossHistory: isset($raw['total_loss_history']) ? (bool) $raw['total_loss_history'] : null,
                ownersCount:      isset($raw['owners_count'])      ? (int)  $raw['owners_count']      : null,
                lienStatus:       $raw['lien_status']     ?? null,
                seizureStatus:    $raw['seizure_status']  ?? null,
                insuranceCount:   isset($raw['insurance_count']) ? (int) $raw['insurance_count'] : null,
                sellType:         $raw['sell_type']     ?? null,
                sellTypeRaw:      $raw['sell_type_raw'] ?? null,
                registrationYearMonth: isset($raw['registration_year_month']) ? (int) $raw['registration_year_month'] : null,
                registrationDate: $raw['registration_date'] ?? null,
            );
        }

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
            transmission: self::mapValue($raw['transmission'] ?? null, self::$transmissionMap),
            fuel:         self::mapValue($raw['fuel']         ?? null, self::$fuelMap),
            bodyType:     self::mapValue($raw['bodyType']     ?? null, self::$bodyTypeMap),
            driveType:    self::mapValue($raw['driveType']    ?? null, self::$driveMap),
            color:        isset($raw['color'])    ? ucfirst(strtolower(trim($raw['color'])))  : null,
            engineVolume: isset($raw['engineCC']) ? round((float) $raw['engineCC'] / 1000, 1) : null,
            trim:         $raw['trim']            ?? null,
        );
    }
}
