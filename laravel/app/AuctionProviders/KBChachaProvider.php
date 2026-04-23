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
                ->map(fn ($row) => (array) $row)
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
        // When reading from DB columns $raw is a flat row.
        // When reading from mock JSON it is the original KBChacha API payload.
        $fromDb = isset($raw['id']);

        if ($fromDb) {
            return new LotDTO(
                id:           $raw['id'],
                source:       $this->getKey(),
                sourceName:   $this->getName(),
                make:         $raw['make']  ?? '',
                model:        $raw['model'] ?? '',
                year:         (int) ($raw['year']    ?? 0),
                price:        (int) ($raw['price']   ?? 0),
                mileage:      (int) ($raw['mileage'] ?? 0),
                damage:       $raw['damage'] ?? null,
                title:        $raw['title']  ?? 'Clean',
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
                fuelEconomy:  isset($raw['fuel_economy'])  ? (float) $raw['fuel_economy']  : null,
                cylinders:    isset($raw['cylinders'])     ? (int)   $raw['cylinders']     : null,
                trim:         $raw['trim']          ?? null,
                retailValue:  isset($raw['retail_value']) ? (int) $raw['retail_value'] : null,
                repairCost:   isset($raw['repair_cost'])  ? (int) $raw['repair_cost']  : null,
                hasAccident:      isset($raw['has_accident'])      ? (bool) $raw['has_accident']      : null,
                floodHistory:     isset($raw['flood_history'])     ? (bool) $raw['flood_history']     : null,
                totalLossHistory: isset($raw['total_loss_history']) ? (bool) $raw['total_loss_history'] : null,
                ownersCount:      isset($raw['owners_count'])      ? (int)  $raw['owners_count']      : null,
                plateNumber:      $raw['plate_number'] ?? null,
                dealerName:       $raw['dealer_name']  ?? null,
                dealerPhone:      $raw['dealer_phone'] ?? null,
                warrantyText:     $raw['warranty_text']   ?? null,
                paidOptions:      isset($raw['paid_options']) ? (is_array($raw['paid_options']) ? $raw['paid_options'] : json_decode($raw['paid_options'], true)) : null,
                lienStatus:       $raw['lien_status']     ?? null,
                seizureStatus:    $raw['seizure_status']  ?? null,
                taxPaid:          isset($raw['tax_paid'])          ? (bool) $raw['tax_paid']          : null,
                mileageGrade:     $raw['mileage_grade']   ?? null,
                newCarPriceRatio: isset($raw['new_car_price_ratio']) ? (int) $raw['new_car_price_ratio'] : null,
                sellType:         $raw['sell_type']     ?? null,
                sellTypeRaw:      $raw['sell_type_raw'] ?? null,
                registrationYearMonth: isset($raw['registration_year_month']) ? (int) $raw['registration_year_month'] : null,
                insuranceCount:   isset($raw['insurance_count']) ? (int) $raw['insurance_count'] : null,
                seatColor:        $raw['seat_color']    ?? null,
                seatCount:        isset($raw['seat_count'])      ? (int)  $raw['seat_count']      : null,
                isDomestic:       isset($raw['is_domestic'])     ? (bool) $raw['is_domestic']     : null,
                importType:       $raw['import_type']   ?? null,
                dealerCompany:    $raw['dealer_company']    ?? null,
                dealerLocation:   $raw['dealer_location']   ?? null,
                dealerDescription: $raw['dealer_description'] ?? null,
                registrationDate: $raw['registration_date'] ?? null,
            );
        }

        // Mock / legacy JSON path
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
