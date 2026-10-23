<?php

namespace App\Support;

use App\Support\Taxonomy\TaxonomyCatalog;

/**
 * The lot as a picker sends it to a client: photos, price, the car and its history in
 * Russian. Nothing that leads to the listing itself — no platform link, VIN, plate or
 * lot id — so the client gets the car through the picker.
 */
final class ClientLotCard
{
    /** Telegram's limit for a photo caption. */
    private const CAPTION_MAX = 1024;

    /**
     * @param  array<string, mixed>  $lot  LotDTO::toArray()
     * @param  array<string, mixed>|null  $inspection  lot_inspections row
     */
    public static function html(array $lot, ?array $inspection = null): string
    {
        $lines = array_map(
            fn (array $l) => $l[0] === 'title' ? '<b>'.e($l[1]).'</b>' : ($l[0] === 'price' ? '<b>'.e($l[1]).'</b>' : e($l[1])),
            self::lines($lot, $inspection),
        );

        return mb_substr(implode("\n", $lines), 0, self::CAPTION_MAX);
    }

    /** @param array<string, mixed> $lot */
    public static function text(array $lot, ?array $inspection = null): string
    {
        return implode("\n", array_map(fn (array $l) => $l[1], self::lines($lot, $inspection)));
    }

    /**
     * @return list<array{0: string, 1: string}> [kind, text]
     */
    private static function lines(array $lot, ?array $inspection): array
    {
        $label = fn (string $field, ?string $v) => $v === null || $v === ''
            ? null
            : (TaxonomyCatalog::labelMap($field, 'ru')[$v] ?? $v);

        $model = $lot['modelEn'] ?: ($lot['modelGroupEn'] ?: $lot['model']);
        $title = trim(implode(' ', array_filter([
            (string) ($lot['year'] ?: ''), $lot['makeEn'] ?: $lot['make'], $model, $lot['trimEn'] ?? null,
        ])));
        $out = [['title', $title], ['price', '₩'.self::num((int) $lot['price'])]];

        $specs = array_filter([
            $lot['mileage'] ? self::num((int) $lot['mileage']).' км' : null,
            mb_strtolower((string) $label('fuel', $lot['fuel'] ?? null)) ?: null,
            mb_strtolower((string) $label('transmission', $lot['transmission'] ?? null)) ?: null,
            mb_strtolower((string) $label('drive_type', $lot['driveType'] ?? null)) ?: null,
            ! empty($lot['engineVolume']) && $lot['engineVolume'] >= 0.5 ? rtrim(rtrim(number_format((float) $lot['engineVolume'], 1, '.', ''), '0'), '.').' л' : null,
        ]);
        if ($specs) {
            $out[] = ['line', implode(' · ', $specs)];
        }
        $about = array_filter([
            ($c = $label('color', $lot['color'] ?? null)) ? 'цвет: '.mb_strtolower($c) : null,
            ! empty($lot['firstRegDate']) ? 'регистрация: '.self::month((string) $lot['firstRegDate']) : null,
        ]);
        if ($about) {
            $out[] = ['line', self::ucfirst(implode(' · ', $about))];
        }

        $history = array_filter([
            match ($lot['hasAccident'] ?? null) { true => 'было ДТП', false => 'без ДТП', default => null },
            isset($lot['ownersCount']) ? 'владельцев: '.$lot['ownersCount'] : null,
            isset($lot['insuranceCount']) ? 'страховых случаев: '.$lot['insuranceCount'] : null,
            ($lot['floodHistory'] ?? null) === true ? 'было затопление' : null,
            ($lot['totalLossHistory'] ?? null) === true ? 'признан тотальным' : null,
            ! empty($lot['repairCost']) ? 'ремонт по страховым: ₩'.self::num((int) $lot['repairCost']) : null,
        ]);
        if ($history) {
            $out[] = ['line', 'История: '.implode(' · ', $history)];
        }
        if ($inspection) {
            $marks = array_filter([$inspection['accident_detail'] ?? null, $inspection['outer_detail'] ?? null]);
            $out[] = ['line', 'Осмотр'.(! empty($inspection['inspection_date']) ? ' '.self::day((string) $inspection['inspection_date']) : '')
                .': '.($marks ? implode('; ', $marks) : 'замечаний нет')];
        }
        if (! empty($lot['options']) && is_array($lot['options'])) {
            $out[] = ['line', 'Опций: '.count($lot['options'])];
        }

        return $out;
    }

    private static function num(int $n): string
    {
        return number_format($n, 0, '.', ' ');
    }

    private static function month(string $date): string
    {
        return preg_match('/^(\d{4})-(\d{2})/', $date, $m) ? "{$m[2]}.{$m[1]}" : $date;
    }

    private static function day(string $date): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m) ? "{$m[3]}.{$m[2]}.{$m[1]}" : $date;
    }

    private static function ucfirst(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }
}
