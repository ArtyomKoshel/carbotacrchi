<?php

namespace Tests\Unit\Catalog;

use App\Catalog\Che168\ChaboshiReport;
use PHPUnit\Framework\TestCase;

class ChaboshiReportTest extends TestCase
{
    private static function describe(string $kind): array
    {
        $root = dirname(__DIR__, 3);
        $report = json_decode((string) file_get_contents(dirname($root) . "/contracts/fixtures/chaboshi_{$kind}.json"), true);

        return (new ChaboshiReport("{$root}/resources/catalog/che168/chaboshi.json"))->describe($report);
    }

    public function test_ev_report_names_the_verdicts_the_water_traces_and_the_battery(): void
    {
        $c = self::describe('ev');

        $this->assertSame(['2026-10-28', 'S', 'проверка электромобиля'], [$c['date'], $c['grade'], $c['product']]);
        $this->assertSame([
            ['label' => 'Крупное ДТП', 'text' => 'не выявлено', 'level' => 'ok'],
            ['label' => 'Затопление', 'text' => 'нет, но есть следы воды', 'level' => 'warn'],
            ['label' => 'Пожар', 'text' => 'не выявлено', 'level' => 'ok'],
        ], $c['verdicts']);
        $accident = collect($c['systems'])->firstWhere('name', 'Проверка на ДТП');
        $this->assertSame(70, $accident['checked']);
        $this->assertContains(['part' => 'задняя панель кузова', 'notes' => 'деформация 5 см–50 см, перекраска, рихтовка 5 см–50 см'], $accident['findings']);
        $this->assertContains(['part' => 'крыло (лев. задн.)', 'notes' => 'скол краски ≥3 см'], $accident['findings']);
        $this->assertContains('днище', $accident['unchecked']);
        $flood = collect($c['systems'])->firstWhere('name', 'Проверка на затопление');
        $this->assertContains(['part' => 'ковёр пола с накладками (прав. перед.)', 'notes' => 'следы воды изнутри'], $flood['findings']);

        $battery = array_column($c['battery'], 1, 0);
        $this->assertSame('93,6 % · данные от 27.10.2026', $battery['Состояние батареи (SOH)']);
        $this->assertSame('отличное, S', $battery['Оценка батареи']);
        $this->assertSame('LFP (литий-железо-фосфат)', $battery['Тип']);
        $this->assertSame('60,48 кВт·ч', $battery['Ёмкость']);
        $this->assertSame('30–80 %', $battery['Быстрая зарядка']);
        $this->assertSame('486,5–510 км (весна, 15…25 ℃)', $battery['Запас хода по оценке']);
        $this->assertContains(['', '413,1–482 км (зима, −20…10 ℃)'], $c['battery']);
    }

    public function test_fuel_report_without_findings_says_so_and_has_no_battery(): void
    {
        $c = self::describe('fuel');

        $this->assertSame(['не выявлено', 'не выявлено', 'не выявлено'], array_column($c['verdicts'], 'text'));
        $accident = collect($c['systems'])->firstWhere('name', 'Проверка на ДТП');
        $this->assertSame([], $accident['findings']);
        $extra = collect($c['systems'])->firstWhere('name', 'Дополнительно');
        $this->assertSame([['part' => 'коробка передач', 'notes' => 'течь масла'], ['part' => 'двигатель', 'notes' => 'следы подтёков']], $extra['findings']);
        $this->assertSame([], $c['battery']);
    }

    public function test_an_unknown_term_stays_chinese_and_an_unknown_verdict_is_no_data(): void
    {
        $root = dirname(__DIR__, 3);
        $c = (new ChaboshiReport("{$root}/resources/catalog/che168/chaboshi.json"))->describe([
            'systems' => [['name' => '新检测', 'checked' => 3, 'findings' => [['part' => '新部件', 'notes' => [['新问题', null]]]], 'unchecked' => []]],
        ]);

        $this->assertSame(['name' => '新检测', 'checked' => 3, 'findings' => [['part' => '新部件', 'notes' => '新问题']], 'unchecked' => []], $c['systems'][0]);
        $this->assertSame(['нет данных', 'нет данных', 'нет данных'], array_column($c['verdicts'], 'text'));
    }
}
