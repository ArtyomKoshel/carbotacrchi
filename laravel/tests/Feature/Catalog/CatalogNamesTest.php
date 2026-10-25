<?php

namespace Tests\Feature\Catalog;

use App\Services\SearchQuery;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseOnce;
use Tests\TestCase;

/** A search that names the car as text (AI search, bot, saved subscriptions) reaches it through the catalog. */
final class CatalogNamesTest extends TestCase
{
    use RefreshesDatabaseOnce;

    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        $now = now();
        $make = fn (string $name) => DB::table('ref_makes')->insertGetId(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        $model = fn (int $makeId, string $name) => DB::table('ref_models')->insertGetId(['make_id' => $makeId, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        $gen = fn (int $modelId, string $code) => DB::table('ref_generations')->insertGetId(['model_id' => $modelId, 'code' => $code, 'created_at' => $now, 'updated_at' => $now]);

        $this->id['bmw'] = $make('BMW');
        $this->id['kia'] = $make('Kia');
        $this->id['5'] = $model($this->id['bmw'], '5 Series');
        $this->id['carnival'] = $model($this->id['kia'], 'Carnival');
        $this->id['grand'] = $model($this->id['kia'], 'Grand Carnival');
        $this->id['g60'] = $gen($this->id['5'], 'G60');
        $this->id['g30'] = $gen($this->id['5'], 'G30');
        $this->id['g30fl'] = $gen($this->id['5'], 'G30 · LCI');
        $this->id['530e'] = DB::table('ref_modifications')->insertGetId(['generation_id' => $this->id['g30'], 'code' => '530e', 'created_at' => $now, 'updated_at' => $now]);
        $this->id['msport'] = DB::table('ref_complectations')->insertGetId(['make_id' => $this->id['bmw'], 'name' => 'M Sport', 'created_at' => $now, 'updated_at' => $now]);
        $this->id['prestige'] = DB::table('ref_complectations')->insertGetId(['make_id' => $this->id['kia'], 'name' => 'Prestige', 'created_at' => $now, 'updated_at' => $now]);
    }

    public function test_make_model_and_generation_by_name(): void
    {
        $q = SearchQuery::fromArray(['make' => 'bmw', 'model' => '5 series', 'generation' => 'G30']);

        $this->assertFalse($q->matchesNothing);
        $this->assertSame([$this->id['bmw'], $this->id['5']], [$q->refMakeId, $q->refModelId]);
        $this->assertEqualsCanonicalizing([$this->id['g30'], $this->id['g30fl']], $q->refGenerationIds, 'a code takes its facelifts too');
        $this->assertSame([$this->id['g60']], SearchQuery::fromArray(['make' => 'BMW', 'model' => '5 Series', 'generation' => '5 Series (G60)'])->refGenerationIds);
    }

    public function test_a_model_alone_and_the_exact_name_first(): void
    {
        $alone = SearchQuery::fromArray(['model' => 'Carnival']);

        $this->assertSame([$this->id['kia'], $this->id['carnival']], [$alone->refMakeId, $alone->refModelId], '"Carnival" is not "Grand Carnival"');
    }

    public function test_the_old_trim_is_read_as_engine_and_complectation(): void
    {
        $q = SearchQuery::fromArray(['make' => 'BMW', 'model' => '5 Series', 'trim' => '530e M 스포츠']);
        $kia = SearchQuery::fromArray(['make' => 'Kia', 'trim' => '프레스티지']);

        $this->assertSame([[$this->id['530e']], [$this->id['msport']]], [$q->refModificationIds, $q->refComplectationIds]);
        $this->assertSame([$this->id['prestige']], $kia->refComplectationIds);
    }

    /** A subscription to a car the catalog does not know must not turn into "every car". */
    public function test_an_unknown_name_matches_nothing(): void
    {
        foreach ([['make' => 'Lada'], ['make' => 'BMW', 'model' => 'X99'], ['make' => 'BMW', 'model' => '5 Series', 'generation' => 'E39'],
                  ['make' => 'Kia', 'trim' => 'Nonsense']] as $text) {
            $q = SearchQuery::fromArray($text);
            $this->assertTrue($q->matchesNothing, json_encode($text));
            $this->assertSame(0, app(\App\Services\ProviderAggregator::class)->count($q), json_encode($text));
        }
    }

    public function test_ids_from_the_catalog_win_over_text(): void
    {
        $q = SearchQuery::fromArray(['make' => 'Some Old Name', 'refMakeId' => $this->id['kia']]);

        $this->assertFalse($q->matchesNothing);
        $this->assertSame($this->id['kia'], $q->refMakeId);
    }
}
