<?php

namespace App\AuctionProviders;

use App\Dto\LotDTO;
use App\Support\LotPhotos;

/**
 * Che168 (China) lots the collector wrote into `lots`. Prices are in CNY; Che168 gives no
 * insurance or accident history, so those stay unknown rather than «без ДТП».
 */
class Che168Provider extends AbstractDbProvider
{
    public function getKey(): string { return 'che168'; }
    public function getName(): string { return 'Che168'; }

    public function normalize(array $raw): LotDTO
    {
        return new LotDTO(
                id:           $raw['id'],
                source:       $this->getKey(),
                sourceName:   $this->getName(),
                make:         $raw['make']          ?? '',
                model:        $raw['model']         ?? '',
                year:         (int) ($raw['year']    ?? 0),
                price:        (int) ($raw['price']   ?? 0),
                mileage:      (int) ($raw['mileage'] ?? 0),
                location:     $raw['location'] ?? 'China',
                lotUrl:       $raw['lot_url']   ?? '',
                imageUrl:     $raw['image_url'] ?? null,
                vin:          null,
                auctionDate:  $raw['listed_at'] ?? null,
                createdAt:    $raw['created_at'] ?? date('c'),
                transmission: $raw['transmission']  ?? null,
                fuel:         $raw['fuel']          ?? null,
                bodyType:     $raw['body_type']     ?? null,
                driveType:    $raw['drive_type']    ?? null,
                color:        $raw['color']         ?? null,
                engineVolume: isset($raw['engine_volume']) ? (float) $raw['engine_volume'] : null,
                retailValue:  isset($raw['retail_value']) ? (int) $raw['retail_value'] : null,
                seatCount:    isset($raw['seat_count']) ? (int) $raw['seat_count'] : null,
                firstRegDate: $raw['first_reg_date'] ?? null,
                listedAt:     $raw['listed_at'] ?? null,
                photos:       LotPhotos::urls($raw) ?: array_values(array_filter([$raw['image_url'] ?? null])),
                currency:     $raw['currency'] ?? 'CNY',
        );
    }
}
