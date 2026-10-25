<?php

namespace App\Ingest;

use App\Support\LotPhotos;
use App\Support\OptionBits;
use DateTimeImmutable;
use DateTimeZone;

/**
 * CarLot v1 (validated, decoded as an associative array) → rows for `lots`
 * and `lot_inspections`, in the shape the old parser wrote them
 * (parser/models.py CarLot.to_db_row, InspectionRecord.to_db_row).
 */
final class LotRowMapper
{
    /** lots.price is KRW; other currencies arrive with multi-platform support. */
    public const STORAGE_CURRENCY = 'KRW';

    /** `lots.id` is the source's own id for Encar (as today), prefixed for any other platform. */
    public static function lotId(string $source, string $sourceId): string
    {
        return $source === 'encar' ? $sourceId : "{$source}:{$sourceId}";
    }

    /**
     * @param  array<string, mixed>  $lot
     * @param  (callable(list<string>): ?string)|null  $optionsMask  codes → lots.options_mask (default: encar_option_catalog bits)
     * @return array<string, mixed>
     */
    public static function lotRow(array $lot, ?callable $optionsMask = null): array
    {
        $optionsMask ??= OptionBits::maskForStorage(...);
        $v = $lot['vehicle'];
        $l = $lot['listing'];
        $h = $lot['history'] ?? [];
        $extra = $lot['extra'] ?? [];
        $options = $l['options'] ?? [];
        $paidOptions = $l['paid_options'] ?? [];

        return [
            'id' => self::lotId($lot['source'], $lot['source_id']),
            'source' => $lot['source'],
            'make' => $v['make'],
            'model' => $v['model'],
            'model_group' => $v['model_group'] ?? null,
            'year' => $v['year'],
            'price' => self::krw($l['price']),
            'mileage' => $l['mileage_km'],
            'vin' => $v['vin'] ?? null,
            'body_type' => $v['body_type'] ?? null,
            'transmission' => $v['transmission'] ?? null,
            'fuel' => $v['fuel'] ?? null,
            'drive_type' => $v['drive_type'] ?? null,
            'has_accident' => self::bool($h['has_accident'] ?? null),
            'flood_history' => self::bool($h['flood'] ?? null),
            'owners_count' => $h['owners_count'] ?? null,
            'insurance_count' => $h['insurance_count'] ?? null,
            'location' => $l['location'] ?? null,
            'color' => $v['color'] ?? null,
            'seat_color' => $v['seat_color'] ?? null,
            'badge_group' => $v['badge_group'] ?? null,
            'badge' => $v['badge'] ?? null,
            'trim' => $v['trim'] ?? null,
            'trim_en' => $v['trim_en'] ?? null,
            'engine_volume' => $v['engine_volume_l'] ?? null,
            'lien_status' => $h['lien_status'] ?? null,
            'seizure_status' => $h['seizure_status'] ?? null,
            'total_loss_history' => self::bool($h['total_loss'] ?? null),
            'retail_value' => isset($l['retail_value']) ? self::krw($l['retail_value']) : null,
            'repair_cost' => isset($h['repair_cost']) ? self::krw($h['repair_cost']) : null,
            'image_url' => $l['image_url'] ?? null,
            'lot_url' => $lot['url'],
            'raw_data' => $extra === [] ? null : self::json($extra),
            'plate_number' => $v['plate_number'] ?? null,
            'first_reg_date' => $v['first_registration'] ?? null,
            'listed_at' => self::korean($l['listed_at'] ?? null),
            'options' => $options === [] ? null : self::json(array_values($options)),
            'options_mask' => $optionsMask(array_values($options)),
            'paid_options' => $paidOptions === [] ? null : self::json(array_values($paidOptions)),
            'sell_type' => $l['sell_type'] ?? null,
            'sell_type_raw' => $l['sell_type_raw'] ?? null,
            'seat_count' => $v['seat_count'] ?? null,
            ...LotPhotos::encode(($l['photos'] ?? []) ?: null),
        ];
    }

    /**
     * @param  array<string, mixed>  $lot
     * @return array<string, mixed>|null
     */
    public static function inspectionRow(array $lot): ?array
    {
        $i = $lot['inspection'] ?? null;
        if (! is_array($i)) {
            return null;
        }
        $details = $i['details'] ?? [];

        return [
            'lot_id' => self::lotId($lot['source'], $lot['source_id']),
            'source' => $lot['source'],
            'cert_no' => $i['cert_no'] ?? null,
            'inspection_date' => $i['inspected_on'] ?? null,
            'valid_from' => $i['valid_from'] ?? null,
            'valid_until' => $i['valid_until'] ?? null,
            'report_url' => $i['report_url'] ?? null,
            'first_registration' => $i['first_registration'] ?? null,
            'inspection_mileage' => $i['mileage_km'] ?? null,
            'insurance_fee' => isset($i['insurance_fee']) ? self::krw($i['insurance_fee']) : null,
            'has_accident' => self::bool($i['has_accident'] ?? null),
            'has_outer_damage' => self::bool($i['has_outer_damage'] ?? null),
            'has_flood' => self::bool($i['has_flood'] ?? null),
            'has_fire' => self::bool($i['has_fire'] ?? null),
            'has_tuning' => self::bool($i['has_tuning'] ?? null),
            'accident_detail' => $i['accident_detail'] ?? null,
            'outer_detail' => $i['outer_detail'] ?? null,
            'details' => $details === [] ? null : self::json($details),
        ];
    }

    /** @param array<string, mixed> $lot */
    public static function historyCheckedAt(array $lot): ?string
    {
        return self::utc($lot['history']['observed_at'] ?? null);
    }

    /** @param array{amount: int, currency?: string} $money */
    private static function krw(array $money): int
    {
        $currency = $money['currency'] ?? self::STORAGE_CURRENCY;
        if ($currency !== self::STORAGE_CURRENCY) {
            throw new UnsupportedRecord("currency {$currency} is not stored yet (lots.price is KRW)");
        }

        return (int) $money['amount'];
    }

    private static function bool(?bool $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function utc(?string $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * lots.listed_at holds the platform's listing time in Korea, as the old parser stored it
     * (Encar's date at 00:00); converting it to UTC would move every date one day back.
     */
    private static function korean(?string $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i:s');
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
