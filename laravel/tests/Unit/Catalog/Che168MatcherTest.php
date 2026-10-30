<?php

namespace Tests\Unit\Catalog;

use App\Catalog\Che168\Che168Matcher;
use App\Catalog\Reference;
use App\Catalog\VariantMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Che168MatcherTest extends TestCase
{
    private static function matcher(): Che168Matcher
    {
        $root = dirname(__DIR__, 3) . '/resources/catalog';
        return new Che168Matcher(new Reference("{$root}/reference"), "{$root}/che168/brands.json");
    }

    /**
     * Real Che168 brand / series names (run of 2026-10-29) and the reference model they are.
     *
     * @return array<string, array{string, string, ?string, ?string}>
     */
    public static function series(): array
    {
        return [
            'Latin series as is' => ['保时捷', 'Macan', 'Porsche', 'Macan'],
            'brand before the series' => ['宝马', '宝马X5', 'BMW', 'X5'],
            'BMW 系' => ['宝马', '宝马5系', 'BMW', '5 Series'],
            'Mercedes 级' => ['奔驰', '奔驰E级', 'Mercedes-Benz', 'E-Class'],
            'imported body' => ['奔驰', '奔驰GLE(进口)', 'Mercedes-Benz', 'GLE-Class'],
            'Audi long wheelbase' => ['奥迪', '奥迪A6L', 'Audi', 'A6'],
            'Audi plug-in long wheelbase' => ['奥迪', '奥迪A6L新能源', 'Audi', 'A6'],
            'Chinese name from the dictionary' => ['丰田', '亚洲龙', 'Toyota', 'Avalon'],
            'Lexus' => ['雷克萨斯', '雷克萨斯IS', 'Lexus', 'IS'],
            'Chinese Audi Q6 is not the Q6 e-tron' => ['奥迪', '奥迪Q6', 'Audi', null],
            'brand outside the reference' => ['理想汽车', '理想L8', null, null],
        ];
    }

    #[DataProvider('series')]
    public function test_make_and_model(string $brand, string $series, ?string $make, ?string $model): void
    {
        $m = self::matcher()->match(['make' => $brand, 'model' => $series]);

        $this->assertSame([$make, $model], [$m->make, $m->model]);
        $this->assertNull($m->generation, 'Che168 names no generation: none is guessed');
        $this->assertSame(VariantMatch::UNMATCHED, $m->status);
    }

    public function test_every_named_series_is_in_the_reference(): void
    {
        $root = dirname(__DIR__, 3) . '/resources/catalog';
        $reference = new Reference("{$root}/reference");
        $brands = json_decode((string) file_get_contents("{$root}/che168/brands.json"), true)['brands'];
        foreach ($brands as $brand => $b) {
            $this->assertNotNull($reference->make($b['make']), "{$brand} → {$b['make']}");
            foreach ($b['series'] ?? [] as $series => $model) {
                $this->assertNotNull($reference->model($b['make'], $model), "{$series} → {$b['make']} {$model}");
            }
        }
    }
}
