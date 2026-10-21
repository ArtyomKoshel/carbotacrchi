<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Lots\Pages\ListLots;
use App\Filament\Resources\Lots\LotFormat;
use Database\Factories\LotChangeFactory;
use Database\Factories\LotFactory;
use Livewire\Livewire;

/** Lots list: search, saved tabs with counts, filters; the lot card. */
class LotsTest extends AdminTestCase
{
    public function test_search_by_id_prefix_vin_and_plate(): void
    {
        $this->signIn();
        $a = LotFactory::new()->create(['id' => '41491484', 'vin' => 'KMHLN41BBLU000001', 'plate_number' => '12가3456']);
        $b = LotFactory::new()->create(['id' => '99414914', 'vin' => 'WBA00000000000002', 'plate_number' => '34나7890']);

        $list = fn (string $q) => Livewire::test(ListLots::class)->set('activeTab', 'all')->searchTable($q);

        $list('4149')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]); // prefix only: 99414914 contains 4149 but does not start with it
        $list('LU000001')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
        $list('7890')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        $list('50%')->assertCountTableRecords(0); // LIKE wildcards are literal
    }

    public function test_saved_tab_counts(): void
    {
        $this->signIn();
        LotFactory::new()->count(2)->create(['created_at' => now()]);                // new today
        LotFactory::new()->create(['trim' => null, 'created_at' => now()->subDays(3)]);
        LotFactory::new()->inactive()->create(['trim' => null]);                     // inactive: not «без комплектации»
        $recent = LotFactory::new()->inactive()->create();
        $older = LotFactory::new()->inactive()->create();
        LotChangeFactory::new()->delisted()->create(['lot_id' => $recent->id, 'recorded_at' => now()->subHours(3)]);
        LotChangeFactory::new()->delisted()->create(['lot_id' => $older->id, 'recorded_at' => now()->subDays(2)]);

        $tabs = Livewire::test(ListLots::class)->instance()->getTabs();
        $count = fn (string $tab) => (int) str_replace("\u{00A0}", '', $tabs[$tab]->getBadge());

        $this->assertSame(3, $count('active'));
        $this->assertSame(2, $count('new'));
        $this->assertSame(1, $count('delisted'));
        $this->assertSame(1, $count('no_trim'));
        $this->assertSame(6, $count('all'));
        $this->assertTrue($tabs['active']->isBadgeDeferred(), 'counts load after the table');
    }

    public function test_tabs_filter_the_table_like_their_counts(): void
    {
        $this->signIn();
        $active = LotFactory::new()->create();
        $gone = LotFactory::new()->inactive()->create();
        LotChangeFactory::new()->delisted()->create(['lot_id' => $gone->id]);

        Livewire::test(ListLots::class)
            ->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$gone])
            ->set('activeTab', 'delisted')
            ->assertCanSeeTableRecords([$gone])->assertCanNotSeeTableRecords([$active]);
    }

    public function test_filters_narrow_the_table(): void
    {
        $this->signIn();
        $cheap = LotFactory::new()->create(['price' => 9000000, 'year' => 2016, 'fuel' => 'diesel']);
        $dear = LotFactory::new()->create(['price' => 45000000, 'year' => 2022, 'fuel' => 'gasoline']);

        Livewire::test(ListLots::class)
            ->filterTable('price', ['from' => 10000000])
            ->assertCanSeeTableRecords([$dear])->assertCanNotSeeTableRecords([$cheap]);
        Livewire::test(ListLots::class)
            ->filterTable('fuel', ['diesel'])
            ->assertCanSeeTableRecords([$cheap])->assertCanNotSeeTableRecords([$dear]);
        Livewire::test(ListLots::class)
            ->filterTable('year', ['to' => 2018])
            ->assertCanSeeTableRecords([$cheap])->assertCanNotSeeTableRecords([$dear]);
    }

    public function test_lot_page_with_raw_data_pre_norm_and_no_inspection(): void
    {
        $this->signIn();
        $lot = LotFactory::new()->create([
            'price'    => 21900000,
            'raw_data' => ['ad_status' => 'ADVERTISE', 'pre_norm' => ['trim' => '원래 트림']],
        ]);

        $this->get("/admin/lots/{$lot->id}")
            ->assertOk()
            ->assertSee(LotFormat::won(21900000))
            ->assertSee('₩')
            ->assertDontSee('$21')
            ->assertSee('raw_data.pre_norm')
            ->assertSee('원래 트림')
            ->assertSee('Парсер не получил отчёт осмотра');
    }

    public function test_won_format(): void
    {
        $this->assertSame("₩\u{00A0}21\u{00A0}900\u{00A0}000", LotFormat::won(21900000));
        $this->assertSame('—', LotFormat::won(null));
    }
}
