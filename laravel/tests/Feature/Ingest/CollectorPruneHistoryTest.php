<?php

namespace Tests\Feature\Ingest;

use Database\Factories\LotFactory;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseOnce;
use Tests\TestCase;

/** collector:prune-history drops the stored cards and histories of cars without an active lot, once old. */
final class CollectorPruneHistoryTest extends TestCase
{
    use RefreshesDatabaseOnce {
        beginDatabaseTransaction as beginTestTransactions;
    }

    protected array $connectionsToTransact = ['mysql', 'collector'];

    /** The collector's own DDL, before the test transactions open (DDL would commit them). */
    public function beginDatabaseTransaction(): void
    {
        if (! DB::connection('collector')->getSchemaBuilder()->hasTable('lot_history')) {
            DB::connection('collector')->unprepared((string) file_get_contents(base_path('../collector/migrations/002_lot_history.sql')));
        }
        $this->beginTestTransactions();
    }

    private function history(string $source, string $id, int $daysAgo): void
    {
        DB::connection('collector')->table('lot_history')->insert([
            'source' => $source, 'lot_id' => $id, 'checked_at' => gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400),
            'condition_tags' => '[]', 'fields' => '{}',
        ]);
    }

    /** @return list<string> "source:lot_id" of the rows left */
    private function left(): array
    {
        return DB::connection('collector')->table('lot_history')->orderBy('source')->orderBy('lot_id')
            ->get()->map(fn ($r) => "{$r->source}:{$r->lot_id}")->all();
    }

    private function rows(): void
    {
        LotFactory::new()->create(['id' => '100', 'source' => 'encar', 'is_active' => 1]);
        LotFactory::new()->create(['id' => 'che168:200', 'source' => 'che168', 'currency' => 'CNY', 'is_active' => 1]);
        LotFactory::new()->create(['id' => 'che168:201', 'source' => 'che168', 'currency' => 'CNY', 'is_active' => 0]);
        $this->history('encar', '100', 90);  // active lot: kept at any age
        $this->history('encar', '101', 90);  // rejected by a rule, never a lot
        $this->history('che168', '200', 90); // active
        $this->history('che168', '201', 90); // hidden as sold
        $this->history('che168', '202', 90); // never a lot
        $this->history('che168', '203', 10); // never a lot, but refreshed lately
    }

    public function test_old_rows_of_cars_without_an_active_lot_go(): void
    {
        $this->rows();

        // two rows a window: the pass goes on past windows it keeps whole
        $this->artisan('collector:prune-history', ['--chunk' => 2, '--pause-ms' => 0])->assertSuccessful();

        $this->assertSame(['che168:200', 'che168:203', 'encar:100'], $this->left());
    }

    public function test_dry_run_counts_and_deletes_nothing(): void
    {
        $this->rows();

        $this->artisan('collector:prune-history', ['--dry-run' => true])
            ->expectsTable(['Source', 'Would delete', 'Kept (active lot)'], [['che168', '2', '1'], ['encar', '1', '1']])
            ->assertSuccessful();

        $this->assertCount(6, $this->left());
    }
}
