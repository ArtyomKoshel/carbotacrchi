<?php

namespace App\Filament\Resources\Lots;

use App\Models\Lot;
use App\Support\Taxonomy\TaxonomyLocalizer;

/** Display formats for lots in the panel. Prices are KRW integers, shown in ₩. */
class LotFormat
{
    private const NBSP = "\u{00A0}";

    public static function title(Lot $lot): string
    {
        $make  = $lot->make_en ?: $lot->make;
        $model = $lot->model_en ?: ($lot->model_group_en ?: $lot->model);

        return trim("{$make} {$model}") ?: $lot->id;
    }

    /** Trim (or badge when there is no trim) and the lot id. */
    public static function subtitle(Lot $lot): string
    {
        return implode(' · ', array_filter([$lot->trim_en ?: $lot->badge_en, $lot->id]));
    }

    public static function won(mixed $value): string
    {
        return is_numeric($value) ? '₩' . self::NBSP . self::number((int) $value) : '—';
    }

    public static function km(mixed $value): string
    {
        return is_numeric($value) ? self::number((int) $value) . self::NBSP . 'км' : '—';
    }

    public static function liters(mixed $value): string
    {
        return is_numeric($value) && $value > 0 ? number_format((float) $value, 1, ',', '') . self::NBSP . 'л' : '—';
    }

    public static function yesNo(mixed $value): string
    {
        return $value === null ? '—' : ((bool) $value ? 'да' : 'нет');
    }

    public static function taxonomy(string $field, ?string $value): string
    {
        return filled($value) ? TaxonomyLocalizer::label($field, $value) : '—';
    }

    public static function number(int $value): string
    {
        return number_format($value, 0, ',', self::NBSP);
    }

    /** A value from lot_changes.changes, as the History tab shows it. */
    public static function changeValue(string $field, mixed $v): string
    {
        return match (true) {
            $v === null, $v === ''                                         => '—',
            in_array($field, ['price', 'retail_value', 'repair_cost'], true) => self::won($v),
            $field === 'mileage'                                           => self::km($v),
            $field === 'is_active'                                         => $v ? 'активен' : 'снят',
            is_bool($v)                                                    => $v ? 'да' : 'нет',
            is_scalar($v)                                                  => (string) $v,
            default                                                        => json_encode($v, JSON_UNESCAPED_UNICODE),
        };
    }
}
