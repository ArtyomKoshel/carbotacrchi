<?php

namespace Tests\Feature\Admin;

use App\Livewire\Platform\Runs;
use App\Models\ParseJob;
use App\Services\ParseRuns;
use Database\Factories\LotFactory;
use Database\Factories\ParseJobFactory;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Launching and cancelling runs. parse_jobs rows are read by parser/job_worker.py:
 * filters {} / {"max_pages": N} / {"maker": "<Korean maker>"}, type full, status pending.
 */
class RunsTest extends AdminTestCase
{
    private function launch(array $data)
    {
        return Livewire::test(Runs::class, ['source' => 'encar'])
            ->callAction(TestAction::make('launch')->table(), data: $data);
    }

    public function test_full_run_creates_one_unfiltered_job(): void
    {
        $admin = $this->signIn();

        $this->launch(['mode' => ParseRuns::MODE_FULL])->assertHasNoFormErrors();

        $job = ParseJob::sole();
        $this->assertSame(['encar', 'full', 'pending'], [$job->source, $job->type, $job->status]);
        $this->assertNull($job->getRawOriginal('filters'));
        $this->assertSame("admin:{$admin->username}", $job->triggered_by);
    }

    public function test_test_run_is_limited_to_five_pages(): void
    {
        $this->signIn();

        $this->launch(['mode' => ParseRuns::MODE_TEST])->assertHasNoFormErrors();

        $this->assertSame(['max_pages' => ParseRuns::TEST_PAGES], ParseJob::sole()->filters);
        $this->assertSame(5, ParseRuns::TEST_PAGES);
    }

    public function test_chosen_makers_make_one_job_each(): void
    {
        $this->signIn();
        LotFactory::new()->create(['make' => '현대']);
        LotFactory::new()->create(['make' => '기아']);

        $this->launch(['mode' => ParseRuns::MODE_MAKERS, 'makers' => ['현대', '기아']])->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing([['maker' => '현대'], ['maker' => '기아']], ParseJob::all()->pluck('filters')->all());
        $this->assertSame(2, ParseJob::where('type', 'full')->where('status', 'pending')->count());
    }

    public function test_makers_mode_needs_at_least_one_maker(): void
    {
        $this->signIn();

        $this->launch(['mode' => ParseRuns::MODE_MAKERS, 'makers' => []])->assertHasFormErrors(['makers' => 'required']);
        $this->assertSame(0, ParseJob::count());
    }

    public function test_launching_while_a_run_is_running_queues_behind_it(): void
    {
        $this->signIn();
        $running = ParseJobFactory::new()->running()->create();

        $c = Livewire::test(Runs::class, ['source' => 'encar']);
        $this->assertStringStartsWith("Сейчас идёт прогон #{$running->id}", $c->instance()->runningNotice());
        $c->callAction(TestAction::make('launch')->table(), data: ['mode' => ParseRuns::MODE_FULL])
            ->assertHasNoFormErrors();

        $this->assertSame('running', $running->fresh()->status, 'the running job is not touched');
        $this->assertSame(1, ParseJob::where('status', 'pending')->count());
        $this->assertSame($running->id, app(ParseRuns::class)->current('encar')->id, 'the running one stays current');
    }

    public function test_proxy_estimate_scales_the_last_unfiltered_run(): void
    {
        $runs = app(ParseRuns::class);
        $this->assertNull($runs->estimateProxy('encar', ParseRuns::MODE_FULL), 'no run, no estimate');

        ParseJobFactory::new()->successful(proxyBytes: 2_000_000_000, pages: 2000)->create();
        // a newer partial run must not become the basis
        ParseJobFactory::new()->successful(proxyBytes: 10_000)->create(['filters' => ['maker' => '현대']]);

        $this->assertSame(2_000_000_000, $runs->estimateProxy('encar', ParseRuns::MODE_FULL)['bytes']);
        $this->assertSame(5_000_000, $runs->estimateProxy('encar', ParseRuns::MODE_TEST)['bytes']); // 2e9 / 2000 pages * 5

        LotFactory::new()->count(3)->create(['make' => '현대']);
        LotFactory::new()->create(['make' => '기아']);
        $this->assertSame(1_500_000_000, $runs->estimateProxy('encar', ParseRuns::MODE_MAKERS, ['현대'])['bytes']); // 3 of 4 lots
        $this->assertNull($runs->estimateProxy('encar', ParseRuns::MODE_MAKERS, []));
    }

    public function test_cancel_stops_an_unfinished_job_only(): void
    {
        $this->signIn();
        $running = ParseJobFactory::new()->running()->create();

        Livewire::test(Runs::class, ['source' => 'encar'])
            ->callAction('cancel', arguments: ['job' => $running->id]);
        $this->assertSame('cancelled', $running->fresh()->status);

        $done = ParseJobFactory::new()->successful()->create();
        $this->assertFalse(app(ParseRuns::class)->cancel($done));
        $this->assertSame('done', $done->fresh()->status);
    }

    public function test_cancel_cannot_reach_another_platforms_job(): void
    {
        $this->signIn();
        $foreign = ParseJobFactory::new()->running()->create(['source' => 'kbcha']);

        Livewire::test(Runs::class, ['source' => 'encar'])
            ->callAction('cancel', arguments: ['job' => $foreign->id]);

        $this->assertSame('running', $foreign->fresh()->status);
    }

    public function test_counted_runs_follow_the_lifecycle_rules(): void
    {
        $runs = app(ParseRuns::class);
        $ok = ParseJobFactory::new()->successful()->create();
        $partial = ParseJobFactory::new()->successful()->create(['filters' => ['max_pages' => 5]]);

        $this->assertNull($runs->rejection($ok));
        $this->assertStringStartsWith('filtered', $runs->rejection($partial));
        $this->assertSame($ok->id, $runs->lastSuccessful('encar')->id);
    }

    public function test_current_run_card_polls_and_shows_progress(): void
    {
        $this->signIn();
        ParseJobFactory::new()->running(pct: 40)->create();

        Livewire::test(Runs::class, ['source' => 'encar'])
            ->assertSeeHtml('wire:poll.5s')
            ->assertSee('40,0 %')
            ->assertSee('Поиск лотов');

        ParseJob::query()->update(['status' => 'done']);
        Livewire::test(Runs::class, ['source' => 'encar'])->assertDontSeeHtml('wire:poll.5s');
    }
}
