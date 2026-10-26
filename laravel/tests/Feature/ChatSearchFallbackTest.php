<?php

namespace Tests\Feature;

use App\Services\ChatSearchService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseOnce;
use Tests\TestCase;

/** The AI search without the AI: words to filters, and words that refine the filters already set. */
final class ChatSearchFallbackTest extends TestCase
{
    use RefreshesDatabaseOnce;

    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.api_key' => '']);
        $now = now();
        $variant = 0;
        foreach (['Audi' => ['A5', 'A6'], 'BMW' => ['5 Series', 'X5']] as $make => $models) {
            $makeId = $this->id[$make] = DB::table('ref_makes')->insertGetId(['name' => $make, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($models as $model) {
                $modelId = $this->id[$model] = DB::table('ref_models')->insertGetId(['make_id' => $makeId, 'name' => $model, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('ref_variants')->insert(['source' => 'encar', 'hash' => sha1((string) ++$variant), 'status' => 'matched',
                    'make_id' => $makeId, 'model_id' => $modelId, 'lots' => 10, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        Cache::flush();
        (new \ReflectionProperty(ChatSearchService::class, 'makesModels'))->setValue(null, null);
    }

    public function test_a_model_typed_in_cyrillic_and_an_option(): void
    {
        $q = app(ChatSearchService::class)->parseAndSearch('ауди А5 понарамный люк')['query'];

        $this->assertSame([$this->id['Audi'], $this->id['A5']], [$q->refMakeId, $q->refModelId]);
        $this->assertSame(['010'], $q->options);
    }

    public function test_x5_is_not_the_5_series(): void
    {
        $q = app(ChatSearchService::class)->parseAndSearch('бмв Х5 кожа подогрев руля')['query'];

        $this->assertSame($this->id['X5'], $q->refModelId);
        $this->assertEqualsCanonicalizing(['014', '082'], $q->options);
    }

    public function test_words_add_to_the_filters_set(): void
    {
        $current = ['query' => ['make' => 'Audi', 'refMakeId' => $this->id['Audi'], 'priceMax' => 30000000], 'total' => 0, 'description' => ''];

        $r = app(ChatSearchService::class)->parseAndSearch('а5 с люком', $current);

        $this->assertTrue($r['refined']);
        $this->assertSame([$this->id['A5'], 30000000, ['010']], [$r['query']->refModelId, $r['query']->priceMax, $r['query']->options]);
    }

    public function test_another_make_starts_over(): void
    {
        $current = ['query' => ['make' => 'Audi', 'refMakeId' => $this->id['Audi'], 'refModelId' => $this->id['A6'], 'priceMax' => 30000000], 'total' => 0, 'description' => ''];

        $r = app(ChatSearchService::class)->parseAndSearch('бмв х5', $current);

        $this->assertFalse($r['refined']);
        $this->assertSame([$this->id['BMW'], $this->id['X5'], 0], [$r['query']->refMakeId, $r['query']->refModelId, $r['query']->priceMax]);
    }

    public function test_ranges_in_any_form(): void
    {
        $q = app(ChatSearchService::class)->parseAndSearch('ауди а5 2018-2021 от 20 до 30 млн пробег до 100 тыс не битая')['query'];

        $this->assertSame([2018, 2021, 20000000, 30000000, 0, 100000, false],
            [$q->yearFrom, $q->yearTo, $q->priceMin, $q->priceMax, $q->mileageMin, $q->mileageMax, $q->hasAccident]);

        $q = app(ChatSearchService::class)->parseAndSearch('x5 не старше 2020 до 20000$ пробег 50000 2.0-3.0')['query'];

        $this->assertSame([2020, 0, 0, 26000000, 50000, 2.0, 3.0],
            [$q->yearFrom, $q->yearTo, $q->priceMin, $q->priceMax, $q->mileageMax, $q->engineMin, $q->engineMax]);
    }

    public function test_a_modification_and_a_complectation_by_name(): void
    {
        $now = now();
        $g05 = DB::table('ref_generations')->insertGetId(['model_id' => $this->id['X5'], 'code' => 'G05', 'created_at' => $now, 'updated_at' => $now]);
        $mod = fn (string $code) => DB::table('ref_modifications')->insertGetId(['generation_id' => $g05, 'code' => $code, 'created_at' => $now, 'updated_at' => $now]);
        [$d40, $i40] = [$mod('xDrive40d'), $mod('xDrive40i')];
        $mod('2.0');
        $msport = DB::table('ref_complectations')->insertGetId(['make_id' => $this->id['BMW'], 'name' => 'M Sport', 'created_at' => $now, 'updated_at' => $now]);

        $q = app(ChatSearchService::class)->parseAndSearch('x5 40d m sport 2.0')['query'];

        $this->assertSame([[$d40], [$msport]], [$q->refModificationIds, $q->refComplectationIds], '"2.0" is the engine filter, not a modification');
        $this->assertNotContains($i40, $q->refModificationIds);
        $this->assertSame([], $q->driveTypes);
    }

    public function test_another_model_of_the_make_keeps_the_rest(): void
    {
        $current = ['query' => ['make' => 'Audi', 'refMakeId' => $this->id['Audi'], 'refModelId' => $this->id['A6'], 'priceMax' => 30000000], 'total' => 0, 'description' => ''];

        $r = app(ChatSearchService::class)->parseAndSearch('a5', $current);

        $this->assertSame([$this->id['A5'], 30000000], [$r['query']->refModelId, $r['query']->priceMax]);
    }
}
