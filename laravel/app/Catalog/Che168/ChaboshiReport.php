<?php

namespace App\Catalog\Che168;

/**
 * A Chaboshi (查博士) inspection report of a Che168 lot (lot_inspections.details.chaboshi, written by
 * the collector) as the lot card shows it, in Russian by resources/catalog/che168/chaboshi.json.
 * A term the file does not know stays Chinese.
 *
 * It is an inspection, not an insurance history: «no major accident» is Chaboshi's verdict by its
 * standard, shown as such, never as «без ДТП». Water traces below its flood standard are shown too:
 * the report itself names them.
 */
class ChaboshiReport
{
    private const ACCIDENT = '事故车检测';

    private const FLOOD = '泡水车检测';

    /** Findings that are traces of water in the flood check */
    private const WATER = ['水痕', '霉斑', '积水', '泥沙', '锈蚀'];

    /** @var array<string, array<string, string>>|null */
    private ?array $terms = null;

    public function __construct(private readonly ?string $file = null) {}

    /**
     * @param  array<string, mixed>  $report  details.chaboshi
     * @return array<string, mixed>
     */
    public function describe(array $report): array
    {
        $systems = array_values(array_filter((array) ($report['systems'] ?? []), 'is_array'));
        $flood = collect($systems)->firstWhere('name', self::FLOOD);
        $water = $flood && collect($flood['findings'] ?? [])->contains(
            fn ($f) => collect($f['notes'] ?? [])->contains(fn ($n) => in_array($n[0] ?? null, self::WATER, true)));

        return [
            'provider' => 'Chaboshi',
            'date' => $report['inspected_on'] ?? null,
            'grade' => $report['grade'] ?? null,
            'product' => isset($report['product']) ? $this->term('products', $report['product']) : null,
            'mileage_km' => $report['mileage_km'] ?? null,
            'verdicts' => [
                self::verdict('Крупное ДТП', $report['major_accident'] ?? null),
                ($report['flood'] ?? null) === false && $water
                    ? ['label' => 'Затопление', 'text' => 'нет, но есть следы воды', 'level' => 'warn']
                    : self::verdict('Затопление', $report['flood'] ?? null),
                self::verdict('Пожар', $report['fire'] ?? null),
            ],
            'systems' => array_values(array_filter(array_map(fn (array $s) => $this->system($s), $systems))),
            'battery' => is_array($report['battery'] ?? null) ? $this->battery($report['battery']) : [],
        ];
    }

    /** @return array{label: string, text: string, level: ?string} */
    private static function verdict(string $label, mixed $found): array
    {
        return match ($found) {
            true => ['label' => $label, 'text' => 'выявлено', 'level' => 'bad'],
            false => ['label' => $label, 'text' => 'не выявлено', 'level' => 'ok'],
            default => ['label' => $label, 'text' => 'нет данных', 'level' => null],
        };
    }

    /** @param array<string, mixed> $s @return array<string, mixed>|null a system with something to say */
    private function system(array $s): ?array
    {
        $findings = array_map(fn (array $f) => [
            'part' => $this->part((string) ($f['part'] ?? '')),
            'notes' => implode(', ', array_map(fn ($n) => $this->note((string) ($n[0] ?? ''), $n[1] ?? null), (array) ($f['notes'] ?? []))),
        ], array_values(array_filter((array) ($s['findings'] ?? []), 'is_array')));
        $unchecked = array_map(fn ($p) => $this->part((string) $p), (array) ($s['unchecked'] ?? []));
        if ($findings === [] && $unchecked === [] && $s['name'] !== self::ACCIDENT) {
            return null;
        }

        return [
            'name' => $this->term('systems', (string) $s['name']),
            'checked' => $s['checked'] ?? null,
            'findings' => $findings,
            'unchecked' => $unchecked,
        ];
    }

    /**
     * A finding and its detail: «内侧水痕» refines «水痕» (only the detail), a size follows the finding
     * («变形 5cm-50cm»), any other detail goes in brackets.
     */
    private function note(string $finding, ?string $detail): string
    {
        $name = $this->term('notes', $finding);

        return match (true) {
            $detail === null || $detail === '' => $name,
            $finding !== '' && str_contains($detail, $finding) => $this->term('notes', $detail),
            (bool) preg_match('/^[\d≥≤<>]/u', $detail) => $name . ' ' . str_replace(['cm', '-'], [' см', '–'], $detail),
            default => "{$name} ({$this->term('notes', $detail)})",
        };
    }

    /** @param array<string, mixed> $b @return list<array{0: string, 1: string}> */
    private function battery(array $b): array
    {
        $num = fn ($v, int $d = 1) => rtrim(rtrim(number_format((float) $v, $d, ',', ' '), '0'), ',');
        $words = fn (?string $v) => $v === null ? null : $this->term('battery', $v);
        $rows = [
            ['Состояние батареи (SOH)', isset($b['soh']) ? $num($b['soh']) . ' %' . (isset($b['data_date']) ? ' · данные от ' . self::day($b['data_date']) : '') : null],
            ['Оценка батареи', implode(', ', array_filter([$words($b['health_level'] ?? null), $b['level'] ?? null]))],
            ['Тип', $words($b['type'] ?? null)],
            ['Ёмкость', isset($b['capacity_kwh']) ? $num($b['capacity_kwh'], 2) . ' кВт·ч' : null],
            ['Напряжение', isset($b['voltage']) ? $num($b['voltage']) . ' В' : null],
            ['Производитель', implode(' · ', array_filter([$words($b['pack_maker'] ?? null), $words($b['cell_brand'] ?? null)]))],
            ['Быстрая зарядка', isset($b['fast_charge_soc']) ? str_replace('-', '–', $b['fast_charge_soc']) . ' %' : null],
        ];
        foreach ((array) ($b['ranges'] ?? []) as $i => $range) {
            $rows[] = [$i === 0 ? 'Запас хода по оценке' : '', $this->range((string) $range)];
        }

        return array_values(array_filter($rows, fn ($r) => $r[1] !== null && $r[1] !== ''));
    }

    /** «486.5-510.0Km(春季:15-25℃)» → «486,5–510 км (весна, 15–25 ℃)» */
    private function range(string $range): string
    {
        if (! preg_match('/^([\d.]+)-([\d.]+)\s*Km\s*[(（]([^:：]+)[:：]\s*([^)）]+)[)）]$/ui', $range, $m)) {
            return $range;
        }
        $km = fn (string $v) => rtrim(rtrim(number_format((float) $v, 1, ',', ''), '0'), ',');

        // «-20-10℃» is −20 to 10 ℃: the dash between them is not a minus
        $temp = preg_match('/^(-?\d+)-(-?\d+)\s*(℃?)$/u', trim($m[4]), $t)
            ? str_replace('-', '−', $t[1]) . '…' . str_replace('-', '−', $t[2]) . ' ℃'
            : $m[4];

        return "{$km($m[1])}–{$km($m[2])} км ({$this->term('seasons', $m[3])}, {$temp})";
    }

    private function part(string $zh): string
    {
        $terms = $this->terms();
        if (isset($terms['parts'][$zh])) {
            return $terms['parts'][$zh];
        }
        foreach ($terms['sides'] as $side => $ru) {  // longest first in the file: 左前 before 左
            if (str_starts_with($zh, $side) && isset($terms['part_stems'][substr($zh, strlen($side))])) {
                return $terms['part_stems'][substr($zh, strlen($side))] . " ({$ru})";
            }
        }

        return $zh;
    }

    private function term(string $group, string $zh): string
    {
        return $this->terms()[$group][$zh] ?? $zh;
    }

    private static function day(string $date): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m) ? "{$m[3]}.{$m[2]}.{$m[1]}" : $date;
    }

    /** @return array<string, array<string, string>> */
    private function terms(): array
    {
        return $this->terms ??= json_decode(
            (string) file_get_contents($this->file ?? resource_path('catalog/che168/chaboshi.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
