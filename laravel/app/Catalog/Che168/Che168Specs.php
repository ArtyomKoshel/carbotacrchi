<?php

namespace App\Catalog\Che168;

/**
 * The card's extended specs Che168 states (lots.raw_data.che168, collector che168 SOURCE_FIELDS),
 * as the lot card's sections: label → value with its unit. Only what Che168 states: its
 * placeholders («-», "", 0, «0kWh») mean unknown and give no row, and nothing is derived (the
 * number of transfers is not the number of owners, a warranty is the maker's terms, not one the
 * next owner keeps, the range is the declared one).
 */
class Che168Specs
{
    private const USE = ['家用' => 'личное', '非营运' => 'некоммерческое', '营运' => 'коммерческое', '租赁' => 'аренда', '公务' => 'служебное'];

    private const INTAKE = ['涡轮增压' => 'турбонаддув', '自然吸气' => 'атмосферный', '机械增压' => 'механический нагнетатель', '双增压' => 'двойной наддув'];

    private const BATTERY = ['磷酸铁锂电池' => 'LFP (литий-железо-фосфат)', '三元锂电池' => 'NMC (тройная литиевая)', '锰酸锂电池' => 'LMO (литий-марганцевая)'];

    private const DIGITS = ['一' => 1, '二' => 2, '两' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9, '十' => 10];

    /**
     * @param  array<string, mixed>  $che  lots.raw_data.che168
     * @return list<array{title: string, rows: list<array{0: string, 1: string}>}>
     */
    public function sections(array $che): array
    {
        $sections = [
            'Документы' => [
                ['Переоформлений', self::count($che['transfercount'] ?? null)],
                ['Страховка до', self::month($che['insurance'] ?? null)],
                ['Техосмотр до', self::month($che['examine'] ?? null)],
                ['Использование', self::named($che['carusename'] ?? null, self::USE)],
                ['Экокласс', self::eco($che['environmental'] ?? null)],
            ],
            'Двигатель и динамика' => [
                ['Впуск', self::named($che['flowmode'] ?? null, self::INTAKE)],
                ['Разгон 0–100 км/ч', self::unit($che['accelerate'] ?? null, 'с')],
                ['Расход по WLTC', self::unit($che['wltc_fuelconsumption'] ?? null, 'л/100 км')],
                ['Расход по NEDC', self::unit($che['nedc_fuelconsumption'] ?? null, 'л/100 км')],
            ],
            'Батарея и зарядка' => [
                ['Ёмкость батареи', self::unit($che['batterypower'] ?? null, 'кВт·ч')],
                ['Запас хода, заявленный', self::unit($che['lifemileage'] ?? null, 'км')],
                ['Тип батареи', self::named($che['batterytype'] ?? null, self::BATTERY)],
                ['Производитель батареи', self::text($che['batterybrand'] ?? null)],
                ['Быстрая зарядка', self::unit($che['quickcharge'] ?? null, 'ч')],
                ['Обычная зарядка', self::unit($che['slowcharge'] ?? null, 'ч')],
                ['Расход энергии', self::unit(preg_replace('/\s*kwh$/i', '', (string) ($che['ev100power'] ?? '')), 'кВт·ч/100 км')],
            ],
            'Гарантия' => [
                ['Гарантия на батарею', self::warranty($che['evwarranty'] ?? null)],
                ['Заводская гарантия', self::flag($che['haswarranty'] ?? null)],
                ['Расширенная гарантия', self::flag($che['isextwarranty'] ?? null)],
            ],
        ];

        $out = [];
        foreach ($sections as $title => $rows) {
            $rows = array_values(array_filter($rows, fn (array $r) => $r[1] !== null));
            if ($rows !== []) {
                $out[] = ['title' => $title, 'rows' => $rows];
            }
        }

        return $out;
    }

    /** A placeholder: «-», "" or nothing. */
    private static function blank(mixed $v): bool
    {
        return $v === null || (is_string($v) && in_array(trim($v), ['', '-', '--', '—', '暂无'], true));
    }

    private static function text(mixed $v): ?string
    {
        return self::blank($v) || !is_scalar($v) ? null : mb_substr(trim((string) $v), 0, 80);
    }

    /** A number above zero with its unit, «60,48 кВт·ч»; 0 is Che168's «unknown». */
    private static function unit(mixed $v, string $unit): ?string
    {
        if (self::blank($v) || !is_numeric($v) || (float) $v <= 0) {
            return null;
        }
        $n = (float) $v;
        $digits = $n == floor($n) ? 0 : (abs($n * 10 - round($n * 10)) < 1e-9 ? 1 : 2);

        return number_format($n, $digits, ',', ' ') . ' ' . $unit;
    }

    /** transfercount: 0 is a real answer here («no transfers»), not a placeholder. */
    private static function count(mixed $v): ?string
    {
        return is_numeric($v) && (int) $v >= 0 && (int) $v == $v ? (string) (int) $v : null;
    }

    /** «2027-07» → «07.2027»; Che168 gives the month, no day is made up. */
    private static function month(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^(\d{4})-(\d{2})$/', trim($v), $m) ? "{$m[2]}.{$m[1]}" : null;
    }

    /** @param array<string, string> $map  a known value translated, an unknown one kept as Che168 wrote it */
    private static function named(mixed $v, array $map): ?string
    {
        $t = self::text($v);
        if ($t === null || $t === '其它' || $t === '其他') {
            return null;
        }

        return $map[$t] ?? $t;
    }

    /** «国VI» → «China 6 (国VI)». */
    private static function eco(mixed $v): ?string
    {
        $t = self::text($v);
        $roman = ['VI' => 6, 'V' => 5, 'IV' => 4, 'III' => 3];
        if ($t !== null && preg_match('/^国(VI|V|IV|III)/u', $t, $m)) {
            return "China {$roman[$m[1]]} ({$t})";
        }

        return $t;
    }

    private static function flag(mixed $v): ?string
    {
        return (string) $v === '1' ? 'есть' : null; // 0 is not «no» on Che168, only «not said»
    }

    /**
     * «八年或19.2万公里» → «8 лет или 192 000 км (八年或19.2万公里)»; any other text as Che168 wrote it.
     * The maker's terms for the battery, not a promise the next owner keeps.
     */
    private static function warranty(mixed $v): ?string
    {
        $t = self::text($v);
        if ($t === null) {
            return null;
        }
        if (preg_match('/^(.+?)年或([\d.]+)万公里$/u', $t, $m) && ($years = self::chineseNumber($m[1])) !== null) {
            $km = number_format((float) $m[2] * 10_000, 0, ',', ' ');
            $word = $years % 10 === 1 && $years % 100 !== 11 ? 'год' : ($years % 10 >= 2 && $years % 10 <= 4 && ($years % 100 < 10 || $years % 100 >= 20) ? 'года' : 'лет');

            return "{$years} {$word} или {$km} км ({$t})";
        }

        return $t;
    }

    /** 八 → 8, 十 → 10, 十二 → 12, 二十 → 20; a plain number as it is. */
    private static function chineseNumber(string $s): ?int
    {
        if (ctype_digit($s)) {
            return (int) $s;
        }
        $chars = mb_str_split($s);
        if (count($chars) === 1) {
            return self::DIGITS[$chars[0]] ?? null;
        }
        if (count($chars) === 2 && $chars[0] === '十') {
            return isset(self::DIGITS[$chars[1]]) ? 10 + self::DIGITS[$chars[1]] : null;
        }
        if (count($chars) === 2 && $chars[1] === '十') {
            return isset(self::DIGITS[$chars[0]]) ? self::DIGITS[$chars[0]] * 10 : null;
        }

        return null;
    }
}
