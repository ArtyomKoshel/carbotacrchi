<?php

namespace Tests\Feature\Admin;

use App\Models\FieldCoverageStat;
use Database\Factories\LotFactory;
use Illuminate\Support\Facades\Schema;

/** fields:compute-coverage on the current schema (it crashed on the dropped registration_year_month). */
class FieldCoverageTest extends AdminTestCase
{
    public function test_command_runs_on_the_current_schema_and_fills_stats(): void
    {
        $this->assertFalse(Schema::hasColumn('lots', 'registration_year_month'), 'precondition: the column is gone');
        LotFactory::new()->count(2)->create(['trim' => 'Prestige']);
        LotFactory::new()->create(['trim' => null]);
        LotFactory::new()->inactive()->create(['trim' => null]); // inactive lots do not count

        $this->artisan('fields:compute-coverage', ['--source' => 'encar'])->assertSuccessful();

        $trim = FieldCoverageStat::where(['source' => 'encar', 'field_name' => 'trim'])->sole();
        $this->assertSame([3, 2, 66.7], [$trim->total_lots, $trim->filled_lots, $trim->coverage_pct]);
        $this->assertNull(FieldCoverageStat::where('field_name', 'registration_year_month')->first());
        $this->assertSame(1, FieldCoverageStat::where(['source' => 'encar', 'field_name' => 'price'])->count(), 'no duplicate checks');
    }

    public function test_command_without_source_covers_every_active_source(): void
    {
        LotFactory::new()->create();
        LotFactory::new()->create(['source' => 'kbcha']);

        $this->artisan('fields:compute-coverage')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['encar', 'kbcha'], FieldCoverageStat::distinct()->pluck('source')->all());
    }

    public function test_matrix_page_shows_the_stats(): void
    {
        $this->signIn();
        LotFactory::new()->create();
        $this->artisan('fields:compute-coverage')->assertSuccessful();

        $this->get('/admin/field-coverage')->assertOk()->assertSee('100,0 %')->assertSee('Комплектация');
    }
}
