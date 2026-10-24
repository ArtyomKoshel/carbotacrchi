<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Logs;
use App\Services\LogFiles;
use App\Services\ParseJobLog;
use Database\Factories\ParseJobFactory;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** A collector job's log is collector.run_log: shown on the job and on the Logs page. */
class CollectorLogsTest extends AdminTestCase
{
    /** run_log lives on the collector connection; roll it back too. */
    protected array $connectionsToTransact = ['mysql', 'collector'];

    /** The collector's own DDL, applied before the test transactions open (DDL would commit them). */
    public function beginDatabaseTransaction(): void
    {
        if (! DB::connection('collector')->getSchemaBuilder()->hasTable('run_log')) {
            DB::connection('collector')->unprepared((string) file_get_contents(base_path('../collector/migrations/003_runs.sql')));
        }
        parent::beginDatabaseTransaction();
    }

    private function logLines(int $jobId): void
    {
        DB::connection('collector')->table('run_log')->insert([
            ['job_id' => $jobId, 'at' => '2026-10-23 09:18:12.764', 'level' => 'info', 'message' => 'full run of encar started'],
            ['job_id' => $jobId, 'at' => '2026-10-23 09:19:11.975', 'level' => 'info', 'message' => 'global p.1: 100/201,346 seen, errors 0'],
            ['job_id' => $jobId, 'at' => '2026-10-23 09:21:02.258', 'level' => 'warning', 'message' => 'cancelled by the admin'],
        ]);
    }

    public function test_job_details_show_the_collector_log(): void
    {
        $job = ParseJobFactory::new()->create(['filters' => ['engine' => 'collector'], 'status' => 'cancelled']);
        $this->logLines($job->id);

        $log = ParseJobLog::tail($job);
        $this->assertNull($log['error']);
        $this->assertSame([
            '2026-10-23 09:18:12 [INFO] full run of encar started',
            '2026-10-23 09:19:11 [INFO] global p.1: 100/201,346 seen, errors 0',
            '2026-10-23 09:21:02 [WARNING] cancelled by the admin',
        ], $log['lines']);
        $this->assertSame(['2026-10-23 09:21:02 [WARNING] cancelled by the admin'], ParseJobLog::tail($job, 300, 'WARNING')['lines']);
        $this->assertCount(1, ParseJobLog::tail($job, 300, null, 'p.1')['lines']);
    }

    public function test_untagged_job_run_by_the_collector_reads_its_log_too(): void
    {
        $job = ParseJobFactory::new()->create(['filters' => null, 'progress' => ['collector' => 'collector/1']]);
        $this->logLines($job->id);

        $this->assertCount(3, ParseJobLog::tail($job)['lines']);
    }

    public function test_collector_job_without_lines_says_so(): void
    {
        $job = ParseJobFactory::new()->create(['filters' => ['engine' => 'collector']]);

        $this->assertSame('Сборщик ещё ничего не записал по этой задаче.', ParseJobLog::tail($job)['error']);
    }

    public function test_logs_page_lists_collector_jobs_newest_first(): void
    {
        $this->signIn();
        $this->logLines(41);

        $entry = app(LogFiles::class)->find('collector:41');
        $this->assertSame('Сборщик', $entry['group']);
        $this->assertSame(3, $entry['lines']);

        Livewire::withQueryParams(['file' => 'collector:41'])->test(Logs::class)
            ->assertSee('global p.1: 100/201,346 seen')
            ->set('level', 'WARNING')
            ->assertSee('cancelled by the admin')
            ->assertDontSee('global p.1');

        $this->assertSame(
            '2026-10-23 09:21:02 [WARNING] cancelled by the admin',
            app(LogFiles::class)->lines($entry, 10)[0],
            'newest first, like the files',
        );
    }

    public function test_with_the_collector_on_the_logs_page_opens_on_its_newest_job(): void
    {
        $this->signIn();
        $this->logLines(41);
        $this->logLines(42);

        config(['ingest.enabled' => true]);
        $this->assertSame('collector:42', app(LogFiles::class)->all()[0]['key'], 'the old parser files, which nothing writes, go last');
        Livewire::test(Logs::class)->assertSet('file', 'collector:42');
    }
}
