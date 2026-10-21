<?php

namespace Tests\Feature\Ingest;

use App\Ingest\CollectorMode;
use App\Models\ParseJob;
use App\Services\LotLifecycle;
use App\Services\ParseRuns;
use Carbon\CarbonImmutable;
use Database\Factories\ParseJobFactory;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseOnce;
use Tests\TestCase;

/**
 * off / shadow / primary (docs/adr/0007): which jobs count for lots, and the
 * collector's scheduled runs.
 */
final class CollectorModeTest extends TestCase
{
    use RefreshesDatabaseOnce;

    private function mode(string $mode): void
    {
        config(['ingest.mode' => $mode, 'ingest.enabled' => $mode === CollectorMode::PRIMARY, 'ingest.sources' => ['encar']]);
    }

    private function schedule(array $over = []): void
    {
        DB::table('parser_schedules')->updateOrInsert(
            ['source' => 'encar'],
            ['enabled' => 1, 'schedule' => 'interval:60', 'interval_minutes' => 60, 'max_pages' => 0, 'maker_filter' => null, ...$over],
        );
    }

    private function collectorJob(array $over = []): ParseJob
    {
        return ParseJobFactory::new()->create(['filters' => ['engine' => 'collector'], ...$over]);
    }

    /** An untagged job of the old parser (in primary: one left from before the switch). */
    private function parserJob(array $over = []): ParseJob
    {
        return ParseJob::withoutEvents(fn () => ParseJobFactory::new()->create($over));
    }

    public function test_in_primary_every_new_job_belongs_to_the_collector(): void
    {
        $this->mode(CollectorMode::SHADOW);
        $shadow = (new ParseRuns)->launch('encar', ParseRuns::MODE_FULL)[0];
        $this->assertNull($shadow->filters, 'shadow: admin runs stay with the old parser');

        $this->mode(CollectorMode::PRIMARY);
        [$full] = (new ParseRuns)->launch('encar', ParseRuns::MODE_FULL);
        [$test] = (new ParseRuns)->launch('encar', ParseRuns::MODE_TEST);
        $reparse = ParseJob::create(['source' => 'encar', 'type' => 'reparse', 'status' => 'pending', 'target_lot_ids' => ['1']]);
        $this->assertSame(['engine' => 'collector'], $full->fresh()->filters);
        $this->assertSame('collector', $test->fresh()->filters['engine']);
        $this->assertSame(ParseRuns::TEST_PAGES, $test->fresh()->filters['max_pages']);
        $this->assertSame(['engine' => 'collector'], $reparse->fresh()->filters);

        $full->update(['status' => 'running']);
        $this->assertTrue(LotLifecycle::parseJobRunning(), 'lifecycle waits for an admin run of the collector');
    }

    public function test_mode_defaults_to_off_and_rejects_unknown_values(): void
    {
        $this->mode('nonsense');
        $this->assertSame(CollectorMode::OFF, CollectorMode::current());
        $this->assertFalse(CollectorMode::collectorRuns());
        $this->assertSame('parser', CollectorMode::feedingEngine());
    }

    public function test_shadow_collector_jobs_do_not_count_for_lots(): void
    {
        $this->mode(CollectorMode::SHADOW);
        $this->collectorJob(['status' => 'running']);
        $parserRun = $this->parserJob(['status' => 'done', 'result' => ['coverage_pct' => 97]]);
        $this->collectorJob(['status' => 'done', 'result' => ['coverage_pct' => 99]]);

        $this->assertFalse(LotLifecycle::parseJobRunning(), 'a running collector job does not pause lifecycle in shadow');
        $this->assertSame([$parserRun->id], array_column((new LotLifecycle('encar'))->loadJobs(), 'id'));
    }

    public function test_primary_only_collector_jobs_count_and_wait_for_ingest(): void
    {
        $this->mode(CollectorMode::PRIMARY);
        $this->parserJob(['status' => 'running']);
        $this->assertFalse(LotLifecycle::parseJobRunning());

        $run = $this->collectorJob(['status' => 'done', 'result' => [
            'coverage_pct' => 97, 'api_total' => 100, 'run_started_at' => '2026-10-21 09:00:00', 'ingest_last_id' => 50,
        ]]);
        $jobs = (new LotLifecycle('encar'))->loadJobs();
        $this->assertSame([$run->id], array_column($jobs, 'id'));

        $this->assertSame([], LotLifecycle::successfulRuns($jobs, 85.0, 0.5, LotLifecycle::ingestOffset()));
        $this->assertStringStartsWith('ingest pending', (new ParseRuns)->rejection($run));

        DB::table('ingest_offsets')->insert(['consumer' => 'core', 'last_id' => 50]);
        $this->assertSame(50, LotLifecycle::ingestOffset());
        $this->assertCount(1, LotLifecycle::successfulRuns($jobs, 85.0, 0.5, LotLifecycle::ingestOffset()));
        $this->assertSame($run->id, (new ParseRuns)->lastSuccessful('encar')?->id);
    }

    public function test_enqueue_does_nothing_when_off(): void
    {
        $this->mode(CollectorMode::OFF);
        $this->schedule();
        $this->artisan('collector:enqueue')->expectsOutputToContain('off')->assertSuccessful();
        $this->assertSame(0, ParseJob::count());
    }

    public function test_enqueue_queues_a_tagged_full_run_when_due(): void
    {
        $this->mode(CollectorMode::SHADOW);
        $this->schedule(['max_pages' => 7]);
        $this->parserJob(['status' => 'running']); // the old parser's run does not block the collector's

        $this->artisan('collector:enqueue')->assertSuccessful();

        $job = ParseJob::where('triggered_by', 'scheduler')->whereRaw(CollectorMode::ENGINE_SQL." = 'collector'")->sole();
        $this->assertEquals(['engine' => 'collector', 'max_pages' => 7], $job->filters);
        $this->assertSame('pending', $job->status);
    }

    public function test_enqueue_waits_for_an_active_job_and_for_the_interval(): void
    {
        $this->mode(CollectorMode::SHADOW);
        $this->schedule(['schedule' => 'interval:120']);
        $active = $this->collectorJob(['status' => 'interrupted']);

        $cmd = new \App\Console\Commands\CollectorEnqueue;
        $now = CarbonImmutable::now('UTC');
        $this->assertSame('a collector job is queued or running', $cmd->reasonToWait('encar', $now));

        $active->update(['status' => 'done']);
        $this->assertSame('not due (every 120 min)', $cmd->reasonToWait('encar', $now));
        $this->assertNull($cmd->reasonToWait('encar', $now->addMinutes(121)));
    }

    public function test_enqueue_cron_schedule_and_disabled_schedule(): void
    {
        $this->mode(CollectorMode::PRIMARY);
        $cmd = new \App\Console\Commands\CollectorEnqueue;

        $this->schedule(['schedule' => 'cron:0 3 * * *']);
        $this->assertNull($cmd->reasonToWait('encar', CarbonImmutable::parse('2026-10-21 03:00:00', 'UTC')));
        $this->assertSame('not due (cron)', $cmd->reasonToWait('encar', CarbonImmutable::parse('2026-10-21 03:01:00', 'UTC')));

        $this->schedule(['enabled' => 0]);
        $this->assertSame('schedule disabled', $cmd->reasonToWait('encar', CarbonImmutable::now('UTC')));
        $this->assertSame('schedule disabled', $cmd->reasonToWait('kcar', CarbonImmutable::now('UTC')));
    }

    public function test_enqueue_dry_run_and_maker_filter(): void
    {
        $this->mode(CollectorMode::SHADOW);
        $this->schedule(['maker_filter' => '현대']);
        $this->artisan('collector:enqueue', ['--dry-run' => true])->expectsOutputToContain('would queue')->assertSuccessful();
        $this->assertSame(0, ParseJob::count());
        $this->artisan('collector:enqueue')->assertSuccessful();
        $this->assertEquals(['engine' => 'collector', 'maker' => '현대'], ParseJob::sole()->filters);
    }

    public function test_cutover_refuses_outside_primary(): void
    {
        $this->mode(CollectorMode::SHADOW);
        $this->artisan('ingest:cutover')->expectsOutputToContain('not primary')->assertFailed();
    }
}
