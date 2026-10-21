<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Lots\Pages\ListLots;
use App\Models\ParseJob;
use App\Services\LotReparseQueue;
use Database\Factories\LotFactory;
use Database\Factories\ParseJobFactory;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Re-parse requests. The row format is what parser/job_worker.py reads:
 * type=reparse, status=pending, target_lot_ids = JSON list of lot id strings.
 */
class ReparseTest extends AdminTestCase
{
    public function test_one_lot_makes_one_job_in_the_parser_format(): void
    {
        $lot = LotFactory::new()->create(['id' => '41491484']);

        $r = app(LotReparseQueue::class)->queue([$lot], 'admin:test');

        $this->assertSame(1, $r['queued']);
        $row = DB::table('parse_jobs')->sole();
        $this->assertSame(['encar', 'reparse', 'pending', 'admin:test'], [$row->source, $row->type, $row->status, $row->triggered_by]);
        $this->assertNull($row->filters);
        $this->assertSame(['41491484'], json_decode($row->target_lot_ids, true), 'a JSON list of strings');
    }

    public function test_bulk_is_split_by_source(): void
    {
        $encar = LotFactory::new()->count(2)->create();
        $kbcha = LotFactory::new()->create(['source' => 'kbcha']);

        app(LotReparseQueue::class)->queue([...$encar, $kbcha]);

        $this->assertEqualsCanonicalizing($encar->pluck('id')->all(), ParseJob::where('source', 'encar')->sole()->target_lot_ids);
        $this->assertSame([$kbcha->id], ParseJob::where('source', 'kbcha')->sole()->target_lot_ids);
    }

    public function test_lots_already_waiting_are_not_queued_twice(): void
    {
        [$a, $b] = LotFactory::new()->count(2)->create()->all();
        ParseJobFactory::new()->create(['type' => 'reparse', 'status' => 'pending', 'target_lot_ids' => [$a->id]]);
        ParseJobFactory::new()->create(['type' => 'reparse', 'status' => 'done', 'target_lot_ids' => [$b->id]]);

        $r = app(LotReparseQueue::class)->queue([$a, $b]);

        $this->assertSame(['queued' => 1, 'skipped' => 1], ['queued' => $r['queued'], 'skipped' => $r['skipped']]);
        $this->assertSame([$b->id], ParseJob::find($r['jobs'][0])->target_lot_ids, 'a finished job does not block a new request');

        $again = app(LotReparseQueue::class)->queue([$a, $b]);
        $this->assertSame(0, $again['queued']);
        $this->assertSame([], $again['jobs']);
    }

    public function test_lots_table_caps_the_selection_at_500(): void
    {
        $this->signIn();

        $table = Livewire::test(ListLots::class)->instance()->getTable();

        $this->assertSame(500, LotReparseQueue::MAX_LOTS);
        $this->assertSame(LotReparseQueue::MAX_LOTS, $table->getMaxSelectableRecords());
    }

    public function test_bulk_action_and_row_action_queue_jobs(): void
    {
        $this->signIn();
        $lots = LotFactory::new()->count(3)->create();

        Livewire::test(ListLots::class)
            ->selectTableRecords($lots->pluck('id')->all())
            ->callAction(TestAction::make('reparse')->table()->bulk())
            ->assertNotified();

        $this->assertEqualsCanonicalizing($lots->pluck('id')->all(), ParseJob::sole()->target_lot_ids);
    }

    public function test_reparse_needs_the_lots_permission(): void
    {
        $this->signIn($this->limited(['lots-browse']));
        LotFactory::new()->create();

        Livewire::test(ListLots::class)->assertActionHidden(TestAction::make('reparse')->table()->bulk());
    }
}
