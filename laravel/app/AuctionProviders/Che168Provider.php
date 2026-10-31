<?php

namespace App\AuctionProviders;

use App\Catalog\Che168\Che168Options;
use App\Catalog\Che168\Che168Specs;
use App\Dto\LotDTO;
use App\Support\LotPhotos;

/**
 * Che168 (China) lots the collector wrote into `lots`. Prices are in CNY; the collector gets no
 * insurance or accident history from Che168, so those stay unknown rather than «без ДТП».
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
                options:      $this->options($raw),
                currency:     $raw['currency'] ?? 'CNY',
                sourceSpecs:  app(Che168Specs::class)->sections((array) ((self::json($raw['raw_data'] ?? null) ?? [])['che168'] ?? [])) ?: null,
        );
    }

    /**
     * The highlighted options (codes «che168:101»), named here: Encar's option catalog does not
     * know Che168's ids. Null when the collector has not fetched them: unknown, not «none».
     *
     * @return list<array<string, mixed>>|null
     */
    private function options(array $raw): ?array
    {
        $codes = self::json($raw['options'] ?? null);
        if (!is_array($codes) || $codes === []) {
            return null;
        }
        $names = (array) ((self::json($raw['raw_data'] ?? null) ?? [])['che168_options'] ?? []);

        return app(Che168Options::class)->describe(array_values($codes), $names);
    }

    private static function json(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }
}
