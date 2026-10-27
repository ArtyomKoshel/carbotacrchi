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
     * @param  list<string>  $calc  the landed-cost lines the picker chose to add (mini app calculator)
     */
    public static function html(array $lot, ?array $inspection = null, string $username = '', array $calc = []): string
    {
        $parts = [];
        foreach (self::lines($lot, $inspection, $username, $calc) as [$kind, $text]) {
            $parts[] = match ($kind) {
                'header' => $text,
                'title'  => '<b>'.e($text).'</b>',
                'spec'   => e($text),
                'price'  => '<b>'.e($text).'</b>',
                'total'  => '<b>'.e($text).'</b>',
                'contact' => $text,
                default  => e($text),
            };
        }

        return mb_substr(implode("\n", $parts), 0, self::CAPTION_MAX);
    }

    /**
     * @param  array<string, mixed>  $lot
     * @param  list<string>  $calc
     */
    public static function text(array $lot, ?array $inspection = null, string $username = '', array $calc = []): string
    {
        return implode("\n", array_map(fn (array $l) => $l[1], self::lines($lot, $inspection, $username, $calc)));
    }

    /**
     * @return list<array{0: string, 1: string}> [kind, text]
     */
    private static function lines(array $lot, ?array $inspection, string $username = '', array $calc = []): array
    {
        $label = fn (string $field, ?string $v) => $v === null || $v === ''
            ? null
            : (TaxonomyCatalog::labelMap($field, 'ru')[$v] ?? $v);

        $place = $lot['catalog'] ?? null;

        // Car title in uppercase: "BMW 5 SERIES (G60) 520I M SPORT"
        $titleParts = [];
        if ($place) {
            if (!empty($place['make'])) $titleParts[] = mb_strtoupper((string) $place['make']);
            if (!empty($place['model'])) $titleParts[] = mb_strtoupper((string) $place['model']);
            if (!empty($place['generation'])) $titleParts[] = '(' . mb_strtoupper((string) $place['generation']) . ')';
            if (!empty($place['modification'])) $titleParts[] = mb_strtoupper((string) $place['modification']);
            if (!empty($place['complectation'])) $titleParts[] = mb_strtoupper((string) $place['complectation']);
        } else {
            if (!empty($lot['make'])) $titleParts[] = mb_strtoupper((string) $lot['make']);
            if (!empty($lot['model'])) $titleParts[] = mb_strtoupper((string) $lot['model']);
            if (!empty($lot['trim'])) $titleParts[] = mb_strtoupper((string) $lot['trim']);
        }
        $title = trim(implode(' ', array_filter($titleParts)));

        $out = [
            ['header', 'АВТО ИЗ КОРЕИ 🇰🇷'],
            ['blank', ''],
            ['title', $title],
            ['blank', ''],
        ];

        // Specs with 🔶
        if (! empty($lot['firstRegDate'])) {
            $out[] = ['spec', '🔶 Регистрация: ' . self::month((string) $lot['firstRegDate'])];
        }
        if (! empty($lot['mileage'])) {
            $out[] = ['spec', '🔶 Пробег: ' . self::num((int) $lot['mileage']) . ' км'];
        }
        $fuelLabel = $label('fuel', $lot['fuel'] ?? null);
        $engine = (! empty($lot['engineVolume']) && $lot['engineVolume'] >= 0.5)
            ? rtrim(rtrim(number_format((float) $lot['engineVolume'], 1, '.', ''), '0'), '.') . ' л'
            : null;
        if ($fuelLabel || $engine) {
            $engineLine = implode(' ', array_filter([
                $fuelLabel ? mb_strtolower((string) $fuelLabel) : null,
                $engine,
            ]));
            $out[] = ['spec', '🔶 Двигатель: ' . $engineLine];
        }
        $driveLabel = $label('drive_type', $lot['driveType'] ?? null);
        if ($driveLabel) {
            $out[] = ['spec', '🔶 Привод: ' . mb_strtolower((string) $driveLabel)];
        }
        $transLabel = $label('transmission', $lot['transmission'] ?? null);
        if ($transLabel) {
            $out[] = ['spec', '🔶 КПП: ' . mb_strtolower((string) $transLabel)];
        }

        // History
        $historyParts = array_filter([
            match ($lot['hasAccident'] ?? null) { true => 'было ДТП', false => 'без ДТП', default => null },
            isset($lot['ownersCount']) ? ((int) $lot['ownersCount'] === 0 ? 'первый владелец' : 'смен владельца: ' . $lot['ownersCount']) : null,
        ]);
        if ($historyParts) {
            $out[] = ['spec', '🔶 История: ' . implode(' · ', $historyParts)];
        }

        // Price
        $out[] = ['blank', ''];
        $out[] = ['price', '❗ ЦЕНА: ₩' . self::num((int) $lot['price'])];

        // Landed cost, as the picker's calculator counted it
        if ($calc !== []) {
            $out[] = ['blank', ''];
            foreach ($calc as $line) {
                $out[] = [match (true) { $line === '' => 'blank', str_starts_with($line, 'Итого') => 'total', default => 'calc' }, $line];
            }
        }

        // Contact
        $out[] = ['blank', ''];
        $contactName = $username ? '@' . ltrim($username, '@') : '';
        $out[] = ['contact', '😊 Заинтересовал автомобиль? | Для уточнения деталей:'];
        if ($contactName) {
            $out[] = ['contact', $contactName . ' 🤝'];
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
