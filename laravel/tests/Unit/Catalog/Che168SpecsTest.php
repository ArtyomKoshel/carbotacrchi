<?php

namespace Tests\Unit\Catalog;

use App\Catalog\Che168\Che168Specs;
use PHPUnit\Framework\TestCase;

class Che168SpecsTest extends TestCase
{
    /** @return array<string, array<string, string>> title → label → value */
    private static function specs(array $che): array
    {
        $out = [];
        foreach ((new Che168Specs())->sections($che) as $section) {
            foreach ($section['rows'] as [$label, $value]) {
                $out[$section['title']][$label] = $value;
            }
        }

        return $out;
    }

    public function test_an_electric_car_gets_its_battery_and_charging(): void
    {
        // BYD Yuan PLUS as Che168 sent it (extra-fields-report-2026-10-30)
        $s = self::specs([
            'transfercount' => 0, 'insurance' => '2027-01', 'examine' => '2027-01', 'carusename' => '家用', 'environmental' => '-',
            'flowmode' => '其它', 'accelerate' => '7.3', 'wltc_fuelconsumption' => '0', 'nedc_fuelconsumption' => '-',
            'batterypower' => 60.48, 'lifemileage' => 510, 'batterytype' => '磷酸铁锂电池', 'batterybrand' => '弗迪',
            'quickcharge' => 0.5, 'slowcharge' => 8.64, 'ev100power' => '12.5kWh', 'evwarranty' => '首任车主不限年限/里程（责任免除条款以官方为准）',
            'haswarranty' => 1, 'isextwarranty' => 0,
        ]);

        $this->assertSame([
            'Ёмкость батареи' => '60,48 кВт·ч',
            'Запас хода, заявленный' => '510 км',
            'Тип батареи' => 'LFP (литий-железо-фосфат)',
            'Производитель батареи' => '弗迪',
            'Быстрая зарядка' => '0,5 ч',
            'Обычная зарядка' => '8,64 ч',
            'Расход энергии' => '12,5 кВт·ч/100 км',
        ], $s['Батарея и зарядка']);
        $this->assertSame(['Переоформлений' => '0', 'Страховка до' => '01.2027', 'Техосмотр до' => '01.2027', 'Использование' => 'личное'], $s['Документы']);
        $this->assertSame(['Разгон 0–100 км/ч' => '7,3 с'], $s['Двигатель и динамика']); // «-», 0 and «其它» give no row
        $this->assertSame('есть', $s['Гарантия']['Заводская гарантия']);
        $this->assertArrayNotHasKey('Расширенная гарантия', $s['Гарантия']); // 0 is «not said», not «no»
    }

    public function test_a_petrol_car_shows_no_battery_from_che168s_zeros(): void
    {
        // Nissan Qashqai: zeros and «0kWh» in the battery fields
        $s = self::specs([
            'transfercount' => 1, 'environmental' => '国VI', 'flowmode' => '涡轮增压', 'wltc_fuelconsumption' => '6.71', 'nedc_fuelconsumption' => '6.7',
            'batterypower' => 0, 'lifemileage' => 0, 'batterytype' => '-', 'batterybrand' => '', 'quickcharge' => 0, 'slowcharge' => 0, 'ev100power' => '0kWh',
        ]);

        $this->assertArrayNotHasKey('Батарея и зарядка', $s);
        $this->assertSame('China 6 (国VI)', $s['Документы']['Экокласс']);
        $this->assertSame(['Впуск' => 'турбонаддув', 'Расход по WLTC' => '6,71 л/100 км', 'Расход по NEDC' => '6,7 л/100 км'], $s['Двигатель и динамика']);
    }

    public function test_a_battery_warranty_reads_in_years_and_km_with_che168s_words(): void
    {
        $this->assertSame('8 лет или 192 000 км (八年或19.2万公里)', self::specs(['evwarranty' => '八年或19.2万公里'])['Гарантия']['Гарантия на батарею']);
        $this->assertSame('10 лет или 250 000 км (十年或25万公里)', self::specs(['evwarranty' => '十年或25万公里'])['Гарантия']['Гарантия на батарею']);
    }

    public function test_nothing_stated_gives_no_section(): void
    {
        $this->assertSame([], (new Che168Specs())->sections([]));
        $this->assertSame([], (new Che168Specs())->sections(['transfercount' => '-', 'insurance' => '', 'examine' => '2027']));
    }
}
