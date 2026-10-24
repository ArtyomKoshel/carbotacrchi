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
        $this->lot('9004', '토요타', '캠리', '2.5 하이브리드');

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

    /** A variant no lot has any more stays (a hand placing may hang on it) but counts no lots. */
    public function test_remap_zeroes_a_variant_its_lots_left(): void
    {
        $this->lot('9401', 'BMW', '5시리즈 (G30)', '520i 럭셔리');
        Artisan::call('catalog:remap', ['--pause-ms' => 0]);
        $old = DB::table('lots')->where('id', '9401')->value('ref_variant_id');
        $this->assertSame(1, (int) DB::table('ref_variants')->where('id', $old)->value('lots'));

        DB::table('lots')->where('id', '9401')->update(['badge' => '530i 럭셔리']);
        Artisan::call('catalog:remap', ['--pause-ms' => 0]);

        $this->assertNotSame($old, DB::table('lots')->where('id', '9401')->value('ref_variant_id'));
        $this->assertSame(0, (int) DB::table('ref_variants')->where('id', $old)->value('lots'));
    }

    public function test_php_and_mysql_hash_a_variant_alike(): void
    {
        $this->lot('9101', 'BMW', 'X7 (G07)', 'xDrive 40i M 스포츠 6인승', null);
        $row = (array) DB::table('lots as l')->where('l.id', '9101')
            ->selectRaw(CatalogLinker::SQL_HASH . ' AS h, l.make, l.model, l.badge_group, l.badge, l.`trim`')->first();

        $this->assertSame(CatalogLinker::hash($row), $row['h']);
    }

    /**
     * Encar's collector strips marketing prefixes ("올 뉴") from lots.model and saves the original
     * in raw_data.model_kr_raw.  SQL_HASH reads the raw name when present, so two lots with the same
     * truncated model but different raw names hash differently and land on different generations.
     */
    public function test_model_kr_raw_separates_variants_with_same_truncated_model(): void
    {
        // K3 Gen I: no raw name — model column is already the right key
        LotFactory::new()->create([
            'id' => '9601', 'source' => 'encar', 'make' => '기아', 'model' => 'K3', 'badge' => '1.6 GDI',
            'trim' => null, 'badge_group' => null, 'is_active' => true, 'raw_data' => ['ad_status' => 'ADVERTISE'],
        ]);
        // K3 All New: collector stored model='K3' (truncated) but kept the original in model_kr_raw
        LotFactory::new()->create([
            'id' => '9602', 'source' => 'encar', 'make' => '기아', 'model' => 'K3', 'badge' => '1.6 GDI',
            'trim' => null, 'badge_group' => null, 'is_active' => true,
            'raw_data' => ['ad_status' => 'ADVERTISE', 'model_kr_raw' => '올 뉴 K3'],
        ]);

        Artisan::call('catalog:remap', ['--pause-ms' => 0]);

        $rows = DB::table('lots')->whereIn('id', ['9601', '9602'])->get()->keyBy('id');
        // Different hashes → different variants
        $this->assertNotSame($rows['9601']->ref_variant_id, $rows['9602']->ref_variant_id);
        // Each lands on a different generation (I vs All New)
        $this->assertNotNull($rows['9601']->ref_generation_id);
        $this->assertNotNull($rows['9602']->ref_generation_id);
        $this->assertNotSame($rows['9601']->ref_generation_id, $rows['9602']->ref_generation_id);
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

    /** release() sets manual=false, re-reads by the rules, and moves the lots to the new result. */
    public function test_release_gives_variant_back_to_rules(): void
    {
        $this->lot('9801', '아우디', 'A5', '40 TFSI 콰트로 쿠페');
        Artisan::call('catalog:remap', ['--pause-ms' => 0]);

        $variantId = DB::table('lots')->where('id', '9801')->value('ref_variant_id');
        $this->assertNotNull($variantId);
        $this->assertSame('unmatched', DB::table('ref_variants')->where('id', $variantId)->value('status'));

        // hand-place to A5 F5
        $f5 = DB::table('ref_generations as g')->join('ref_models as m', 'm.id', '=', 'g.model_id')
            ->where('m.name', 'A5')->where('g.code', 'F5')->value('g.id');
        app(CatalogLinker::class)->assign($variantId, $f5, '40 TFSI quattro', 'gasoline', 'awd', null);
        $this->assertSame($f5, DB::table('lots')->where('id', '9801')->value('ref_generation_id'));
        $this->assertTrue((bool) DB::table('ref_variants')->where('id', $variantId)->value('manual'));

        // release: rules run again; A5 without a stated generation is UNMATCHED → ids cleared
        app(CatalogLinker::class)->release($variantId);

        $this->assertFalse((bool) DB::table('ref_variants')->where('id', $variantId)->value('manual'));
        $this->assertNull(DB::table('lots')->where('id', '9801')->value('ref_generation_id'));
        // the lot still holds its variant id — the variant is just no longer manual
        $this->assertSame($variantId, DB::table('lots')->where('id', '9801')->value('ref_variant_id'));
    }
}
