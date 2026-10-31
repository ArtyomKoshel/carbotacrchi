<?php

namespace Tests\Feature\Ingest;

use App\Ingest\IngestConsumer;
use App\Ingest\IngestPruner;
use Database\Factories\ParseJobFactory;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseOnce;
use Tests\TestCase;

/** ingest:prune deletes only buffer rows the core applied, past retention, of finished runs. */
final class IngestPruneTest extends TestCase
{
    use RefreshesDatabaseOnce {
        beginDatabaseTransaction as beginTestTransactions;
    }

    protected array $connectionsToTransact = ['mysql', 'collector'];

    private const BEFORE = '2026-10-29 00:00:00';

    /** The collector's own DDL, before the test transactions open (DDL would commit them). */
    public function beginDatabaseTransaction(): void
    {
        if (! DB::connection('collector')->getSchemaBuilder()->hasTable('ingest_lots')) {
            DB::connection('collector')->unprepared((string) file_get_contents(base_path('../collector/migrations/001_ingest_lots.sql')));
        }
        $this->beginTestTransactions();
    }

    private function buffer(?int $runId, string $createdAt): int
    {
        return (int) DB::connection('collector')->table('ingest_lots')->insertGetId([
            'run_id' => $runId, 'source' => 'encar', 'source_id' => (string) random_int(1, PHP_INT_MAX),
            'contract' => 'carlot/1', 'payload' => '{}', 'content_hash' => str_repeat('0', 64), 'created_at' => $createdAt,
        ]);
    }

    private function applied(int $lastId): void
    {
        DB::table('ingest_offsets')->updateOrInsert(['consumer' => IngestConsumer::CONSUMER], ['last_id' => $lastId]);
    }

    /** @return list<int> */
    private function left(): array
    {
        return DB::connection('collector')->table('ingest_lots')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_applied_old_rows_of_finished_runs_go_the_rest_stays(): void
    {
        $done = ParseJobFactory::new()->create(['status' => 'done'])->id;
        $interrupted = ParseJobFactory::new()->create(['status' => 'interrupted'])->id;

        $old = [$this->buffer($done, '2026-10-27 10:00:00'), $this->buffer(null, '2026-10-27 10:00:01'), $this->buffer($done, '2026-10-28 10:00:00')];
        $resumable = $this->buffer($interrupted, '2026-10-28 11:00:00');
        $recent = $this->buffer($done, '2026-10-29 09:00:00');
        $pending = $this->buffer($done, '2026-10-29 10:00:00');
        $this->applied($recent);

        $pruner = new IngestPruner(chunk: 2);
        $this->assertSame(3, $pruner->count(self::BEFORE));
        $this->assertSame(3, $pruner->prune(self::BEFORE));

        $this->assertSame([$resumable, $recent, $pending], $this->left(), 'an interrupted run reads its rows back on resume');
        $this->assertNotContains($old[0], $this->left());
    }

    public function test_rows_the_core_has_not_applied_stay_however_old(): void
    {
        $applied = $this->buffer(null, '2026-10-27 10:00:00');
        $waiting = $this->buffer(null, '2026-10-27 10:00:01');
        $this->applied($applied);

        $this->assertSame(1, (new IngestPruner)->prune(self::BEFORE));
        $this->assertSame([$waiting], $this->left());
    }

    public function test_nothing_goes_before_the_core_has_read_the_buffer(): void
    {
        DB::table('ingest_offsets')->delete();
        $this->buffer(null, '2026-10-27 10:00:00');

        $this->assertSame(0, (new IngestPruner)->prune(self::BEFORE));
        $this->assertCount(1, $this->left());
    }

    public function test_command_dry_run_counts_and_deletes_nothing(): void
    {
        $id = $this->buffer(null, '2000-01-01 00:00:00');
        $this->applied($id);

        $this->artisan('ingest:prune', ['--dry-run' => true])->expectsOutputToContain('DRY RUN: 1 buffer rows')->assertSuccessful();
        $this->assertSame([$id], $this->left());

        $this->artisan('ingest:prune')->expectsOutputToContain('Deleted 1 buffer rows')->assertSuccessful();
        $this->assertSame([], $this->left());
    }
}
