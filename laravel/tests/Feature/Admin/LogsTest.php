<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Logs;
use App\Services\LogFiles;
use Database\Factories\ParseJobFactory;
use Livewire\Livewire;

/** The log viewer reads only known log files; clearing is for super admins. */
class LogsTest extends AdminTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = dirname(config('admin.log_file'));
        @mkdir($this->dir . '/jobs', 0775, true);
        array_map('unlink', glob($this->dir . '/*.log*') ?: []);
        array_map('unlink', glob($this->dir . '/jobs/*.log*') ?: []);
        file_put_contents(config('admin.log_file'), "2026-10-21 [INFO] start\n2026-10-21 [ERROR] proxy 407\n2026-10-21 [INFO] done\n");
        file_put_contents(config('admin.log_file') . '.1', "old rotation\n");
    }

    public function test_tail_is_newest_first_and_filters(): void
    {
        $files = app(LogFiles::class);

        $this->assertSame(['2026-10-21 [INFO] done', '2026-10-21 [ERROR] proxy 407', '2026-10-21 [INFO] start'], $files->tail(config('admin.log_file'), 10));
        $this->assertSame(['2026-10-21 [ERROR] proxy 407'], $files->tail(config('admin.log_file'), 10, 'ERROR'));
        $this->assertSame(['2026-10-21 [INFO] done'], $files->tail(config('admin.log_file'), 10, null, 'DONE'));
        $this->assertCount(1, $files->tail(config('admin.log_file'), 1));
    }

    public function test_only_known_files_can_be_opened(): void
    {
        $this->signIn();
        $known = collect(app(LogFiles::class)->all())->pluck('key')->all();

        foreach (['app:../../.env', 'parser:../../../.env', 'job:../../.env', '../.env', 'app:..\\..\\.env', '/etc/passwd'] as $evil) {
            $c = Livewire::withQueryParams(['file' => $evil])->test(Logs::class);
            $this->assertContains($c->get('file'), $known, "{$evil} must fall back to a known file");
            $c->assertDontSee('APP_KEY');

            // set after mount: the page shows nothing and offers no download
            $c->set('file', $evil)->assertDontSee('APP_KEY');
            $this->assertNull($c->instance()->current());
            $c->call('download')->assertNoFileDownloaded();
        }
    }

    public function test_download_returns_the_chosen_log(): void
    {
        $this->signIn();

        Livewire::withQueryParams(['file' => 'parser:0'])->test(Logs::class)
            ->call('download')
            ->assertFileDownloaded('parser.log');
    }

    public function test_super_admin_clears_parser_log_and_finished_job_logs(): void
    {
        $this->signIn();
        $active = ParseJobFactory::new()->running()->create();
        $done = ParseJobFactory::new()->successful()->create();
        file_put_contents("{$this->dir}/jobs/job-{$active->id}.log", "running\n");
        file_put_contents("{$this->dir}/jobs/job-{$done->id}.log", "finished\n");

        Livewire::test(Logs::class)->callAction('clearParser')->callAction('clearJobs');

        $this->assertSame('', file_get_contents(config('admin.log_file')));
        $this->assertFileDoesNotExist(config('admin.log_file') . '.1');
        $this->assertFileExists("{$this->dir}/jobs/job-{$active->id}.log", 'a running job keeps its log');
        $this->assertFileDoesNotExist("{$this->dir}/jobs/job-{$done->id}.log");
    }

    public function test_limited_admin_cannot_clear_even_by_calling_the_action(): void
    {
        $this->signIn($this->limited(['logs']));

        Livewire::test(Logs::class)
            ->call('mountAction', 'clearParser')
            ->assertSet('mountedActions', [])
            ->call('mountAction', 'clearJobs')
            ->assertSet('mountedActions', []);

        $this->assertNotSame('', file_get_contents(config('admin.log_file')));
    }
}
