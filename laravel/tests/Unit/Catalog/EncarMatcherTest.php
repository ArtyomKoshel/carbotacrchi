<?php

namespace Tests\Unit\Catalog;

use App\Catalog\Encar\EncarMatcher;
use App\Catalog\Reference;
use App\Catalog\VariantMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EncarMatcherTest extends TestCase
{
    private static function matcher(): EncarMatcher
    {
        $root = dirname(__DIR__, 3) . '/resources/catalog';
        return new EncarMatcher(new Reference("{$root}/reference"), "{$root}/encar");
    }

    /**
     * Real Encar variants (Korean make, model, badge, trim, year) and what they are.
     *
     * @return array<string, array{array, array}>
     */
    public static function variants(): array
    {
        return [
            'BMW badge holds engine, drive and package' => [
                ['BMW', '5시리즈 (G60)', '530i xDrive M 스포츠 프로 스페셜 에디션', null, 2025],
                ['model' => '5 Series', 'generation' => 'G60', 'modification' => '530i xDrive', 'fuel' => 'gasoline', 'drive' => 'awd',
                    'complectation' => 'M Sport Pro', 'editions' => ['Special Edition']],
            ],
            'BMW X naming and seats' => [
                ['BMW', 'X7 (G07)', 'xDrive 40i M 스포츠 6인승', null, 2023],
                ['modification' => 'xDrive40i', 'complectation' => 'M Sport', 'seats' => 6, 'body' => 'suv'],
            ],
            'BMW M340i 퍼포먼스 is not M340 iPerformance' => [
                ['BMW', '3시리즈 (G20)', 'M340i 퍼포먼스', null, 2022],
                ['modification' => 'M340i', 'complectation' => 'Performance'],
            ],
            'BMW M SUV: Encar G01 is the X3 M F97' => [
                ['BMW', 'X3M (G01)', '3.0 컴페티션', '컴페티션', 2022],
                ['model' => 'X3 M', 'generation' => 'F97', 'modification' => 'X3 M Competition'],
            ],
            'Mercedes old AMG spelling' => [
                ['벤츠', 'C-클래스 W205', 'C43 AMG 4MATIC 쿠페', null, 2019],
                ['modification' => 'AMG C43 4MATIC', 'drive' => 'awd', 'body' => 'coupe'],
            ],
            'Mercedes Maybach and long wheelbase' => [
                ['벤츠', 'S-클래스 W223', '마이바흐 S680 4MATIC 에디션100', null, 2022],
                ['modification' => 'Maybach S680 4MATIC', 'editions' => ['Edition 100']],
            ],
            'Mercedes AMG GT 4-Door is its own model' => [
                ['벤츠', 'AMG GT', '4도어 43 4MATIC+ 다이나믹', null, 2023],
                ['model' => 'AMG GT 4-Door', 'generation' => 'X290', 'modification' => 'AMG GT 43 4MATIC+', 'complectation' => 'Dynamic'],
            ],
            'Mercedes chassis in BadgeDetail' => [
                ['벤츠', '스프린터', '3.0 디젤', 'W907', 2020],
                ['generation' => 'W907', 'modification' => '3.0 Diesel', 'fuel' => 'diesel'],
            ],
            'Audi chassis in BadgeDetail' => [
                ['아우디', 'A6', '35 TDI 콰트로 프리미엄', 'C7', 2017],
                ['generation' => 'C7', 'modification' => '35 TDI quattro', 'fuel' => 'diesel', 'complectation' => 'Premium'],
            ],
            'Audi plug-in' => [
                ['아우디', 'A7 (4K)', '55 TFSI e 콰트로 프리미엄', null, 2024],
                ['modification' => '55 TFSI e quattro', 'fuel' => 'hybrid'],
            ],
            'Porsche Panamera AWD is a 4' => [
                ['포르쉐', '파나메라 (971)', '2.9 AWD E-하이브리드 플래티넘 에디션', null, 2023],
                ['modification' => '4 E-Hybrid', 'fuel' => 'hybrid', 'drive' => 'awd', 'editions' => ['Platinum Edition']],
            ],
            'Porsche 911 without model_en, chassis in BadgeDetail' => [
                ['포르쉐', '911', '카레라 4 GTS 카브리올레', '991', 2018],
                ['generation' => '991', 'modification' => 'Carrera 4 GTS', 'drive' => 'awd', 'body' => 'convertible'],
            ],
            'Mini engine in the model name, generation in BadgeDetail' => [
                ['미니', '쿠퍼 S 컨트리맨', 'ALL4 클래식 런치팩', '2세대', 2019],
                ['model' => 'Countryman', 'generation' => 'F60', 'modification' => 'Cooper S ALL4', 'drive' => 'awd',
                    'complectation' => 'Classic Launch Pack'],
            ],
            'Volvo generation by number' => [
                ['볼보', 'XC90 2세대', 'T8 얼티메이트 브라이트 하이브리드', null, 2024],
                ['generation' => 'II', 'modification' => 'T8', 'fuel' => 'hybrid', 'complectation' => 'Ultimate Bright'],
            ],
            'Land Rover abbreviation' => [
                ['랜드로버', '레인지로버 5세대', 'P530 AB LWB', null, 2024],
                ['generation' => 'L460', 'modification' => 'P530 LWB', 'complectation' => 'Autobiography'],
            ],
            'Volkswagen 4MOTION' => [
                ['폭스바겐', '티구안 2세대', '2.0 TDI 4모션 프레스티지', null, 2020],
                ['generation' => 'AD1', 'modification' => '2.0 TDI 4MOTION', 'drive' => 'awd', 'complectation' => 'Prestige'],
            ],
        ];
    }

    #[DataProvider('variants')]
    public function test_variant(array $lot, array $expected): void
    {
        [$make, $model, $badge, $trim, $year] = $lot;
        $match = self::matcher()->match(['make' => $make, 'model' => $model, 'badge' => $badge, 'trim' => $trim, 'year' => $year]);

        $this->assertSame(VariantMatch::MATCHED, $match->status, json_encode($match->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertEquals($expected, array_intersect_key($match->toArray(), $expected));
    }

    /** What the badge does not say stays unknown: no drive without xDrive / 4MATIC / "4", no default fuel. */
    public function test_nothing_is_assumed(): void
    {
        $matcher = self::matcher();
        $bmw = $matcher->match(['make' => 'BMW', 'model' => '5시리즈 (G30)', 'badge' => '520i 럭셔리']);
        $benz = $matcher->match(['make' => '벤츠', 'model' => 'E-클래스 W213', 'badge' => 'E300 아방가르드']);
        $porsche = $matcher->match(['make' => '포르쉐', 'model' => '911 (992)', 'badge' => '터보 S']);
        $x7 = $matcher->match(['make' => 'BMW', 'model' => 'X7 (G07)', 'badge' => 'M50i 6인승']);

        $this->assertSame(['520i', 'gasoline', null], [$bmw->modification, $bmw->fuel, $bmw->drive]);
        $this->assertSame(['E300', null, null], [$benz->modification, $benz->fuel, $benz->drive]);
        $this->assertSame(['Turbo S', null], [$porsche->modification, $porsche->drive]);
        $this->assertSame(['M50i', null], [$x7->modification, $x7->drive]);
    }

    public function test_the_year_never_picks_a_generation(): void
    {
        $match = self::matcher()->match(['make' => '아우디', 'model' => 'A5', 'badge' => '40 TFSI 콰트로 쿠페', 'year' => 2016]);

        $this->assertSame(VariantMatch::UNMATCHED, $match->status);
        $this->assertSame('Encar names no generation', $match->reason);
    }

    public function test_unknown_words_are_left_for_review(): void
    {
        $match = self::matcher()->match(['make' => '벤츠', 'model' => 'G-클래스 W465', 'badge' => 'AMG G63 지포스 에디션', 'year' => 2026]);

        $this->assertSame(VariantMatch::UNMATCHED, $match->status);
        $this->assertSame(['지포스'], $match->leftover);
    }

    public function test_a_badge_without_engine(): void
    {
        $match = self::matcher()->match(['make' => 'BMW', 'model' => '2시리즈 액티브 투어러 (F45)', 'badge' => '조이', 'year' => 2018]);

        $this->assertSame(VariantMatch::NO_ENGINE, $match->status);
        $this->assertSame('Joy', $match->complectation);
    }

    public function test_english_fields_stand_in_for_empty_korean_ones(): void
    {
        $match = self::matcher()->match(['make' => null, 'make_en' => 'BMW', 'model' => null, 'model_en' => '5 Series (G30)', 'badge' => '520i 럭셔리']);

        $this->assertSame('520i', $match->modification);
    }
}
