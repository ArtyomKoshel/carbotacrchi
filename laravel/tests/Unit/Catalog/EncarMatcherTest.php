<?php

namespace Tests\Unit\Catalog;

use App\Catalog\BadgeGrammar;
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
            'Hyundai Sonata LF: encar_models gives model and generation' => [
                ['현대', 'LF 쏘나타', '2.0 스마트', null, 2016],
                ['model' => 'Sonata', 'generation' => 'LF', 'modification' => '2.0', 'complectation' => 'Smart'],
            ],
            'Kia Ray: complectation-only badge resolved via badge_groups when badge_group absent' => [
                ['기아', '레이', '프레스티지', null, 2015],
                ['model' => 'Ray', 'generation' => 'I', 'fuel' => 'gasoline', 'complectation' => 'Prestige'],
            ],
            'Kia Carnival: the badge group repeats the seats and names the fuel only' => [
                ['기아', '올 뉴 카니발', '9인승 프레스티지', '9인승 프레스티지', 2016],
                ['generation' => 'All New', 'modification' => 'Diesel', 'fuel' => 'diesel', 'drive' => null, 'seats' => 9, 'complectation' => 'Prestige'],
            ],
            'Genesis G90: e-S/C and LWB are engine words' => [
                ['제네시스', 'G90 (RS4)', '가솔린 3.5 터보 e-S/C AWD LWB', null, 2024],
                ['modification' => 'Gasoline 3.5 Turbo e-S/C AWD LWB', 'fuel' => 'gasoline', 'drive' => 'awd'],
            ],
            'Chevrolet: a run-together word beats the shorter one inside it' => [
                ['쉐보레(GM대우)', '말리부', '2.0 LT 디럭스팩', null, 2014],
                ['modification' => '2.0', 'fuel' => null, 'complectation' => 'LT Deluxe Pack'],
            ],
            'KG Mobility: Delight is not Light' => [
                ['KG모빌리티(쌍용)', '뷰티풀 코란도', '디젤 1.6 2WD 딜라이트', '2WD 딜라이트', 2020],
                ['modification' => 'Diesel 1.6 2WD', 'fuel' => 'diesel', 'complectation' => 'Delight'],
            ],
            'Kia K9: Unicode roman numeral in the complectation' => [
                ['기아', '더 K9', '3.8 GDI AWD', '플래티넘Ⅲ', 2017],
                ['modification' => '3.8 GDI AWD', 'drive' => 'awd', 'complectation' => 'Platinum Ⅲ'],
            ],
            'Chevrolet Spark: the model name repeated in the badge is dropped' => [
                ['쉐보레(GM대우)', '스파크', '스파크 S', 'LT', 2010],
                ['model' => 'Spark', 'complectation' => 'S LT'],
            ],
            'Kia Carnival R: Grand Carnival in the badge is the model, GLX the complectation' => [
                ['기아', '카니발 R', '그랜드 카니발 GLX', '고급형', 2012],
                ['model' => 'Carnival', 'generation' => 'R', 'modification' => 'Diesel 2.2', 'fuel' => 'diesel'],
            ],
            'Kia Pride: 5DR is the hatchback body' => [
                ['기아', '더 뉴 프라이드', '5DR 1.6 GDI', '럭셔리', 2013],
                ['modification' => '1.6 GDI', 'body' => 'hatchback', 'complectation' => 'Luxury'],
            ],
            'Audi: Korean words two apart are not read as one ("다이나믹 스포트백")' => [
                ['아우디', 'A3', '35 TDI 다이나믹 스포트백', '8V', 2018],
                ['modification' => '35 TDI', 'complectation' => 'Dynamic', 'body' => 'hatchback'],
            ],
            'Tesla: an electric model of one generation, the badge its powertrain' => [
                ['테슬라', '모델 Y', '롱 레인지 AWD', '롱 레인지 AWD', 2023],
                ['model' => 'Model Y', 'generation' => 'I', 'modification' => 'Long Range AWD', 'fuel' => 'electric', 'drive' => 'awd'],
            ],
            'Tesla: Premium is the complectation' => [
                ['테슬라', '모델 Y', '프리미엄 롱 레인지 AWD', '프리미엄 롱 레인지 AWD', 2025],
                ['modification' => 'Long Range AWD', 'complectation' => 'Premium'],
            ],
            'Jeep: Encar lumps Grand Cherokees, the BadgeDetail names the chassis' => [
                ['지프', '그랜드 체로키', '3.0 디젤 오버랜드', 'WK2', 2016],
                ['model' => 'Grand Cherokee', 'generation' => 'WK2', 'modification' => '3.0 Diesel', 'fuel' => 'diesel', 'complectation' => 'Overland'],
            ],
            'Jeep Wrangler: 4xe hybrid, doors are the body' => [
                ['지프', '랭글러 (JL)', '2.0 오버랜드 파워 탑 4도어 4xe', '오버랜드 파워 탑 4도어 4xe', 2022],
                ['generation' => 'JL', 'modification' => '2.0 4xe', 'fuel' => 'hybrid', 'complectation' => 'Overland Power Top', 'body' => 'suv'],
            ],
            'Ford Explorer: the generation number in BadgeDetail' => [
                ['포드', '익스플로러', '2.3 리미티드 4WD', '5세대', 2018],
                ['model' => 'Explorer', 'generation' => 'V', 'modification' => '2.3 4WD', 'drive' => '4wd', 'complectation' => 'Limited'],
            ],
            'Ford Mustang: generation in the model name' => [
                ['포드', '머스탱 7세대', '2.3 에코부스트 프리미엄 쿠페', '에코부스트 프리미엄 쿠페', 2024],
                ['generation' => 'VII', 'modification' => '2.3 EcoBoost', 'complectation' => 'Premium', 'body' => 'coupe'],
            ],
            'Lexus: the engine is in the model name' => [
                ['렉서스', 'ES300h 7세대', '럭셔리 플러스', '럭셔리 플러스', 2021],
                ['model' => 'ES', 'generation' => 'XV70', 'modification' => 'ES300h', 'fuel' => 'hybrid', 'complectation' => 'Luxury Plus'],
            ],
            'Lexus: an Encar model within one generation by its own dates' => [
                ['렉서스', 'NX300h', '슈프림', '슈프림', 2017],
                ['model' => 'NX', 'generation' => 'AZ10', 'modification' => 'NX300h', 'complectation' => 'Supreme'],
            ],
            'Toyota Camry: chassis in the model name, hybrid in the badge' => [
                ['도요타', '캠리 (XV70)', '2.5 XLE 하이브리드', 'XLE 하이브리드', 2021],
                ['generation' => 'XV70', 'modification' => '2.5 Hybrid', 'fuel' => 'hybrid', 'complectation' => 'XLE'],
            ],
            'Honda: "올뉴" glued to the name, generation in BadgeDetail' => [
                ['혼다', '올뉴어코드', '2.4', '9세대', 2015],
                ['model' => 'Accord', 'generation' => 'IX', 'modification' => '2.4'],
            ],
            'Lincoln Aviator: Black Label written together' => [
                ['링컨', '에비에이터 2세대', '3.0 블랙라벨 AWD', '블랙라벨 AWD', 2021],
                ['generation' => 'II', 'modification' => '3.0 AWD', 'drive' => 'awd', 'complectation' => 'Black Label'],
            ],
            'Cadillac Escalade: generation in BadgeDetail, then in the model name' => [
                ['캐딜락', '에스컬레이드 5세대', '6.2', '스포츠 플래티넘', 2022],
                ['generation' => 'V', 'modification' => '6.2', 'complectation' => 'Sport Platinum'],
            ],
            'Cadillac Escalade: ESV is the long body' => [
                ['캐딜락', '에스컬레이드', '6.2 ESV', '4세대', 2018],
                ['generation' => 'IV', 'modification' => '6.2 ESV'],
            ],
            'BYD: a complectation-only badge of an electric model' => [
                ['BYD', '아토 3', '플러스', '플러스', 2025],
                ['model' => 'Atto 3', 'modification' => 'Atto 3', 'fuel' => 'electric', 'complectation' => 'Plus'],
            ],
            'Nissan Altima: chassis in BadgeDetail' => [
                ['닛산', '알티마', '2.5 스마트', 'L33', 2017],
                ['generation' => 'L33', 'modification' => '2.5', 'complectation' => 'Smart'],
            ],
            'Infiniti Q50: d is diesel' => [
                ['인피니티', 'Q50', '2.2d 프리미엄', '2.2d 프리미엄', 2016],
                ['generation' => 'V37', 'modification' => '2.2d', 'fuel' => 'diesel', 'complectation' => 'Premium'],
            ],
            'Citroën/DS: one Encar make, one reference make' => [
                ['시트로엥/DS', 'DS7 크로스백', '2.0 BlueHDi 그랜드 시크', 'BlueHDi 그랜드 시크', 2020],
                ['make' => 'Citroën/DS', 'model' => 'DS7 Crossback', 'modification' => '2.0 BlueHDi', 'fuel' => 'diesel', 'complectation' => 'Grand Chic'],
            ],
            'Chevrolet imported: Encar\'s second make of Chevrolet' => [
                ['쉐보레', '익스프레스밴', '6.0', null, 2015],
                ['make' => 'Chevrolet', 'model' => 'Express', 'generation' => 'I', 'modification' => '6.0'],
            ],
        ];
    }

    /**
     * Encar's imported Chevrolet ("쉐보레") has a file of its own: its "콜벳" (1953–, every
     * Corvette) is not GM Korea's "콜벳", and names no generation, so the model's only one
     * does not take it.
     */
    public function test_a_second_encar_make_keeps_its_own_models(): void
    {
        $matcher = self::matcher();
        $imported = $matcher->match(['make' => '쉐보레', 'model' => '콜벳', 'badge' => '쿠페', 'trim' => '쿠페']);
        $korea = $matcher->match(['make' => '쉐보레(GM대우)', 'model' => '콜벳', 'badge' => '6.2 쿠페']);

        $this->assertSame(['Chevrolet', 'Corvette', null, VariantMatch::UNMATCHED], [$imported->make, $imported->model, $imported->generation, $imported->status]);
        $this->assertSame('I', $korea->generation);
        $this->assertSame('쉐보레(GM대우)', $matcher->koreanMake('Chevrolet'));
    }

    /** An Encar model holding many generations ("머스탱" 1964–2024) names none: the listing stays at the model. */
    public function test_a_lumped_encar_model_names_no_generation(): void
    {
        $match = self::matcher()->match(['make' => '포드', 'model' => '머스탱', 'badge' => 'GT 쿠페', 'trim' => 'GT 쿠페']);

        $this->assertSame([VariantMatch::UNMATCHED, 'Mustang', null], [$match->status, $match->model, $match->generation]);
    }

    /** A make found by its name or its file's ("Citroën/DS", "citroen-ds"); Encar's "other" makes are makes too. */
    public function test_every_encar_make_is_a_reference_make(): void
    {
        $root = dirname(__DIR__, 3) . '/resources/catalog';
        $reference = new Reference("{$root}/reference");
        $other = self::matcher()->match(['make' => '기타 제조사', 'model' => '기타', 'badge' => '기타 전기차']);

        $this->assertSame('Citroën/DS', $reference->make('Citroën/DS')['make'] ?? null);
        $this->assertSame('Citroën/DS', $reference->make('citroen-ds')['make'] ?? null);
        $this->assertSame(['Other Manufacturers', 'Other'], [$other->make, $other->model]);
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

    /** "Q3" alone is the first Q3 (Encar labels the later ones), but a code Encar states always wins. */
    public function test_a_stated_generation_beats_the_unnumbered_rule(): void
    {
        $matcher = self::matcher();

        $this->assertSame('8U', $matcher->match(['make' => '아우디', 'model' => 'Q3', 'badge' => '2.0 TDI 콰트로 다이나믹'])->generation);
        $this->assertSame('F3', $matcher->match(['make' => '아우디', 'model' => 'Q3 (F3)', 'badge' => '35 TDI 프리미엄'])->generation);
        $this->assertSame('C192', $matcher->match(['make' => '벤츠', 'model' => 'AMG GT C192', 'badge' => 'AMG GT55 4MATIC+'])->generation);
        $this->assertSame('L551', $matcher->match(['make' => '랜드로버', 'model' => '레인지로버 이보크 2세대', 'badge' => 'P250 SE'])->generation);
    }

    /** Badge and BadgeDetail: an import's fuller name, an old copy, or a Korean engine + complectation. */
    public function test_what_of_badge_and_trim_is_read(): void
    {
        $this->assertSame('530i xDrive M 스포츠 프로', EncarMatcher::text('530i xDrive M 스포츠', '530i xDrive M 스포츠 프로'));
        $this->assertSame('530i xDrive M 스포츠', EncarMatcher::text('530i xDrive M 스포츠', '530i xDrive M 스포츠'));
        $this->assertSame('520i 스페셜 에디션', EncarMatcher::text('520i 스페셜 에디션', '520i M 스포츠'), 'an old copy of an earlier badge');
        $this->assertSame('2.0 LPI 시그니처', EncarMatcher::text('2.0 LPI', '시그니처'), 'Korean: engine + complectation');
        $this->assertSame('2.0', EncarMatcher::text('2.0', '(세부등급 없음)'));
        // Encar inconsistently capitalises engine codes across listings: LPI vs LPi are the same word
        $this->assertSame('2.0 LPI 시그니처', EncarMatcher::text('2.0 LPi', '2.0 LPI 시그니처'), 'case-insensitive badge-is-prefix check');
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

    public function test_seats_said_twice_count_once_and_a_different_count_is_left_over(): void
    {
        $kia = (new Reference(dirname(__DIR__, 3) . '/resources/catalog/reference'))->make('kia');

        $same = BadgeGrammar::read('Diesel 9-seater 9-seater Prestige', $kia, null);
        $other = BadgeGrammar::read('Diesel 9-seater 7-seater Prestige', $kia, null);

        $this->assertSame([9, 'Diesel'], [$same['seats'], $same['rest']]);
        $this->assertSame([9, 'Diesel 7-seater'], [$other['seats'], $other['rest']]);
    }

    /** badge_groups lookup is skipped when the lot already carries Encar's badge_group: the caller knows the engine. */
    public function test_badge_groups_not_used_when_lot_has_badge_group(): void
    {
        $match = self::matcher()->match([
            'make' => '기아', 'model' => '레이', 'badge' => '프레스티지',
            'badge_group' => '가솔린 1000cc',
        ]);

        // '프레스티지' alone has no engine code; badge_groups is the only path to fuel,
        // but it is skipped because badge_group is set → NO_ENGINE, not MATCHED
        $this->assertSame(VariantMatch::NO_ENGINE, $match->status);
        $this->assertSame('Prestige', $match->complectation);
    }
}
