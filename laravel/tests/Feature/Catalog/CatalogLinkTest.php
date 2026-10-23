<?php

namespace Tests\Feature\Catalog;

use App\Catalog\CatalogLinker;
use App\Services\SearchQuery;
use Database\Factories\LotFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseOnce;
use Tests\TestCase;

/** Lots on the reference catalog: remap, the variant hash in PHP and MySQL, filters, hand placing. */
final class CatalogLinkTest extends TestCase
{
    use RefreshesDatabaseOnce;

    private function lot(string $id, string $make, string $model, string $badge, ?string $trim = null): void
    {
        LotFactory::new()->create(['id' => $id, 'source' => 'encar', 'make' => $make, 'model' => $model, 'badge' => $badge,
            'trim' => $trim, 'badge_group' => null, 'make_en' => null, 'model_en' => null, 'is_active' => true]);
    }

    public function test_remap_places_lots_by_encar_fields_and_the_filters_find_them(): void
    {
        $this->lot('9001', 'BMW', '5시리즈 (G60)', '530i xDrive M 스포츠', '530i xDrive M 스포츠');
        $this->lot('9002', 'BMW', '5시리즈 (G60)', '520i M 스포츠');
        $this->lot('9003', '벤츠', 'E-클래스 W214', 'E300 4MATIC AMG Line');
        $this->lot('9004', '현대', '쏘나타 (DN8)', '2.0 가솔린');

        Artisan::call('catalog:remap', ['--pause-ms' => 0]);

        $rows = DB::table('lots')->whereIn('id', ['9001', '9002', '9003', '9004'])->get()->keyBy('id');
        $this->assertNotNull($rows['9001']->ref_modification_id);
        $this->assertSame($rows['9001']->ref_generation_id, $rows['9002']->ref_generation_id);
        $this->assertNotSame($rows['9001']->ref_modification_id, $rows['9002']->ref_modification_id);
        $this->assertSame($rows['9001']->ref_complectation_id, $rows['9002']->ref_complectation_id);
        $this->assertNotNull($rows['9003']->ref_model_id);
        $this->assertNull($rows['9004']->ref_model_id, 'a make outside the catalog stays off it');
        $this->assertNotNull($rows['9004']->ref_variant_id, 'but its variant is read once and kept');

        $mod = DB::table('ref_modifications')->find($rows['9001']->ref_modification_id);
        $this->assertSame(['530i xDrive', 'gasoline', 'awd'], [$mod->code, $mod->fuel, $mod->drive]);
        $this->assertNull(DB::table('ref_modifications')->find($rows['9002']->ref_modification_id)->drive, 'no xDrive: drive not stated');

        $found = app(\App\Services\ProviderAggregator::class)->search(SearchQuery::fromArray([
            'refMakeId' => $rows['9001']->ref_make_id, 'refModificationIds' => [$rows['9001']->ref_modification_id], 'sources' => ['encar'],
        ]));
        $this->assertSame(['9001'], array_map(fn ($l) => $l->id, $found->lots));

        $tree = $this->getJson("/api/catalog/{$rows['9001']->ref_make_id}")->assertOk()->json('data');
        $this->assertSame('BMW', $tree['make']['name']);
        $this->assertCount(2, $tree['cells']);
    }

    /** The tables compare case-insensitively; Encar's "LPI" and "LPi" are still two variants, both linked. */
    public function test_variants_that_differ_only_in_case_are_both_linked(): void
    {
        $this->lot('9301', '기아', 'K5 3세대', '2.0 LPI');
        $this->lot('9302', '기아', 'K5 3세대', '2.0 LPi');

        Artisan::call('catalog:remap', ['--pause-ms' => 0]);

        $variants = DB::table('lots')->whereIn('id', ['9301', '9302'])->pluck('ref_variant_id');
        $this->assertCount(2, $variants->filter()->unique());
    }

    public function test_php_and_mysql_hash_a_variant_alike(): void
    {
        $this->lot('9101', 'BMW', 'X7 (G07)', 'xDrive 40i M 스포츠 6인승', null);
        $row = (array) DB::table('lots as l')->where('l.id', '9101')
            ->selectRaw(CatalogLinker::SQL_HASH . ' AS h, l.make, l.model, l.badge_group, l.badge, l.`trim`')->first();

        $this->assertSame(CatalogLinker::hash($row), $row['h']);
    }

    public function test_a_variant_placed_by_hand_moves_its_lots_and_survives_remap(): void
    {
        $this->lot('9201', '아우디', 'A5', '40 TFSI 콰트로 쿠페');
        Artisan::call('catalog:remap', ['--pause-ms' => 0]);
        $variant = DB::table('ref_variants')->where('id', DB::table('lots')->where('id', '9201')->value('ref_variant_id'))->first();
        $this->assertSame('unmatched', $variant->status); // Encar names no generation
        $f5 = DB::table('ref_generations as g')->join('ref_models as m', 'm.id', '=', 'g.model_id')
            ->where('m.name', 'A5')->where('g.code', 'F5')->value('g.id');

        $moved = app(CatalogLinker::class)->assign($variant->id, $f5, '40 TFSI quattro', 'gasoline', 'awd', null);
        Artisan::call('catalog:remap', ['--pause-ms' => 0]);

        $this->assertSame(1, $moved);
        $this->assertSame($f5, DB::table('lots')->where('id', '9201')->value('ref_generation_id'));
        $this->assertTrue((bool) DB::table('ref_variants')->where('id', $variant->id)->value('manual'));
    }
}
