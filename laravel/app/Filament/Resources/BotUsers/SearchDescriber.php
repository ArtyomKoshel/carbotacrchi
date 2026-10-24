<?php

namespace App\Filament\Resources\BotUsers;

use App\Filament\Resources\Lots\LotFormat;
use App\Support\Taxonomy\TaxonomyLocalizer;

/**
 * One line for a saved search query (searches.query, subscriptions.query):
 * every filter the picker set, not only make/model/year/price.
 */
final class SearchDescriber
{
    public static function describe(array $q): string
    {
        $range = static function (string $min, string $max, callable $fmt) use ($q): ?string {
            $from = $q[$min] ?? null;
            $to   = $q[$max] ?? null;
            if ($from === null && $to === null) {
                return null;
            }
            if ($from === null) {
                return 'до ' . $fmt($to);
            }
            if ($to === null) {
                return 'от ' . $fmt($from);
            }
            return $from == $to ? $fmt($from) : $fmt($from) . '–' . $fmt($to);
        };
        $list = static fn (string $key, string $field) => empty($q[$key]) ? null
            : collect((array) $q[$key])->map(fn ($v) => TaxonomyLocalizer::label($field, (string) $v))->implode(', ');
        $flag = static fn (string $key, string $label) => array_key_exists($key, $q) && $q[$key] !== null
            ? $label . ': ' . LotFormat::yesNo($q[$key]) : null;
        $date = static fn ($v) => date('m.Y', strtotime((string) $v));
        $plain = static fn ($v) => (string) $v;

        $parts = [
            $q['make'] ?? null,
            $q['model'] ?? null,
            $q['generation'] ?? null,
            $q['badge'] ?? null,
            $q['trim'] ?? null,
            $range('yearFrom', 'yearTo', $plain),
            ($r = $range('firstRegAfter', 'firstRegBefore', $date)) ? 'рег. ' . $r : null,
            ($r = $range('priceMin', 'priceMax', [LotFormat::class, 'won'])) ? 'цена ' . $r : null,
            ($r = $range('mileageMin', 'mileageMax', [LotFormat::class, 'km'])) ? 'пробег ' . $r : null,
            ($r = $range('engineMin', 'engineMax', [LotFormat::class, 'liters'])) ? 'объём ' . $r : null,
            $list('fuelTypes', 'fuel'),
            $list('driveTypes', 'drive_type'),
            $list('transmissions', 'transmission'),
            $list('bodyTypes', 'body_type'),
            $list('colors', 'color'),
            $flag('hasAccident', 'ДТП'),
            $flag('floodHistory', 'затопление'),
            $flag('totalLossHistory', 'тотал'),
            ($r = $range('insuranceCountMin', 'insuranceCountMax', $plain)) ? 'страх. случаев ' . $r : null,
            ($r = $range('ownersCountMin', 'ownersCountMax', $plain)) ? 'владельцев ' . $r : null,
            ($r = $range('repairCostMin', 'repairCostMax', [LotFormat::class, 'won'])) ? 'ремонт ' . $r : null,
            ($r = $range('seatCountMin', 'seatCountMax', $plain)) ? 'мест ' . $r : null,
            empty($q['options']) ? null : 'опций: ' . count((array) $q['options']),
            ($r = $range('listedAfter', 'listedBefore', $date)) ? 'выставлен ' . $r : null,
            empty($q['vin']) ? null : 'VIN ' . $q['vin'],
        ];

        return collect($parts)->filter(fn ($p) => filled($p))->implode(' · ');
    }
}
