<?php

namespace App\AuctionProviders;

use App\Dto\LotDTO;

class KBChachaProvider extends AbstractDbProvider
{
    public function getKey(): string { return 'kbcha'; }
    public function getName(): string { return 'KBChacha'; }

    public function normalize(array $raw): LotDTO
    {
        return new LotDTO(
                id:           $raw['id'],
                source:       $this->getKey(),
                sourceName:   $this->getName(),
                make:         $raw['make']  ?? '',
                model:        $raw['model'] ?? '',
                year:         (int) ($raw['year']    ?? 0),
                price:        (int) ($raw['price']   ?? 0),
                mileage:      (int) ($raw['mileage'] ?? 0),
                modelEn:      $raw['model_en'] ?? null,
                generation:   $raw['generation'] ?? null,
                location:     $raw['location'] ?? 'Korea',
                lotUrl:       $raw['lot_url']   ?? '',
                imageUrl:     $raw['image_url'] ?? null,
                vin:          $raw['vin']       ?? null,
                auctionDate:  $raw['listed_at'] ?? null,
                createdAt:    $raw['created_at'] ?? date('c'),
                transmission: $raw['transmission']  ?? null,
                fuel:         $raw['fuel']          ?? null,
                bodyType:     $raw['body_type']     ?? null,
                driveType:    $raw['drive_type']    ?? null,
                color:        $raw['color']         ?? null,
                engineVolume: isset($raw['engine_volume']) ? (float) $raw['engine_volume'] : null,
                trim:         $raw['trim']          ?? null,
                retailValue:  isset($raw['retail_value']) ? (int) $raw['retail_value'] : null,
                repairCost:   isset($raw['repair_cost'])  ? (int) $raw['repair_cost']  : null,
                hasAccident:      isset($raw['has_accident'])      ? (bool) $raw['has_accident']      : null,
                floodHistory:     isset($raw['flood_history'])     ? (bool) $raw['flood_history']     : null,
                totalLossHistory: isset($raw['total_loss_history']) ? (bool) $raw['total_loss_history'] : null,
                ownersCount:      isset($raw['owners_count'])      ? (int)  $raw['owners_count']      : null,
                plateNumber:      $raw['plate_number'] ?? null,
                paidOptions:      isset($raw['paid_options']) ? (is_array($raw['paid_options']) ? $raw['paid_options'] : json_decode($raw['paid_options'], true)) : null,
                lienStatus:       $raw['lien_status']     ?? null,
                seizureStatus:    $raw['seizure_status']  ?? null,
                sellType:         $raw['sell_type']     ?? null,
                sellTypeRaw:      $raw['sell_type_raw'] ?? null,
                registrationYearMonth: isset($raw['registration_year_month']) ? (int) $raw['registration_year_month'] : null,
                insuranceCount:   isset($raw['insurance_count']) ? (int) $raw['insurance_count'] : null,
                seatColor:        $raw['seat_color']    ?? null,
                seatCount:        isset($raw['seat_count'])      ? (int)  $raw['seat_count']      : null,
                firstRegDate: $raw['first_reg_date'] ?? null,
                listedAt:     $raw['listed_at'] ?? null,
                options:      isset($raw['options'])
                                  ? (is_string($raw['options']) ? json_decode($raw['options'], true) : $raw['options'])
                                  : null,
        );
    }
}
