<?php

namespace Tests\Feature;

use App\Services\LotLifecycle;
use Carbon\CarbonImmutable;
use Database\Factories\LotFactory;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseOnce;
use Tests\TestCase;

/** A purged lot takes its collector rows (raw responses, fetched history) with it. */
final class LotLifecyclePurgeTest extends TestCase
{
    use RefreshesDatabaseOnce {
        beginDatabaseTransaction as beginTestTransactions;
    }

    protected array $connectionsToTransact = ['mysql', 'collector'];

    /** The collector's own DDL, before the test transactions open (DDL would commit them). */
    public function beginDatabaseTransaction(): void
    {
        $schema = DB::connection('collector')->getSchemaBuilder();
        foreach (['lot_history' => '002_lot_history.sql', 'raw_responses' => '004_raw_responses.sql'] as $table => $file) {
            if (! $schema->hasTable($table)) {
                DB::connection('collector')->unprepared((string) file_get_contents(base_path("../collector/migrations/{$file}")));
            }
        }
        $this->beginTestTransactions();
    }

    private function collectorRows(string $lotId): void
    {
        foreach (['detail', 'record'] as $kind) {
            DB::connection('collector')->table('raw_responses')->insert([
                'source' => 'encar', 'lot_id' => $lotId, 'kind' => $kind, 'content_hash' => str_repeat('0', 64),
                'body' => 'x', 'fetched_at' => '2026-10-20 10:00:00',
            ]);
        }
        DB::connection('collector')->table('lot_history')->insert([
            'source' => 'encar', 'lot_id' => $lotId, 'checked_at' => '2026-10-20 10:00:00', 'condition_tags' => '[]', 'fields' => '{}',
        ]);
    }

    /** @return array{raw: int, history: int} */
    private function left(string $lotId): array
    {
        $c = DB::connection('collector');

        return [
            'raw' => $c->table('raw_responses')->where('lot_id', $lotId)->count(),
            'history' => $c->table('lot_history')->where('lot_id', $lotId)->count(),
        ];
    }

    public function test_purge_deletes_the_collector_rows_of_purged_lots_only(): void
    {
        $cutoff = CarbonImmutable::parse('2026-10-25 00:00:00', 'UTC');
        LotFactory::new()->create(['id' => '40000001', 'source' => 'encar', 'is_active' => 0, 'parsed_at' => '2026-10-20 10:00:00']);
        LotFactory::new()->create(['id' => '40000002', 'source' => 'encar', 'is_active' => 1, 'parsed_at' => '2026-10-28 10:00:00']);
        $this->collectorRows('40000001');
        $this->collectorRows('40000002');
        $this->collectorRows('40000003'); // filtered out by parse_filters: never in lots, still listed

        $lifecycle = new LotLifecycle('encar', 100, 0);
        $counts = $lifecycle->countPurge($cutoff);
        $this->assertSame([1, 2, 1], [$counts['lots'], $counts['raw_responses'], $counts['lot_history']]);

        $done = $lifecycle->purge($cutoff, fn () => false);

        $this->assertSame([1, 2, 1], [$done['lots'], $done['raw_responses'], $done['lot_history']]);
        $this->assertSame(['raw' => 0, 'history' => 0], $this->left('40000001'));
        $this->assertSame(['raw' => 2, 'history' => 1], $this->left('40000002'), 'live lot');
        $this->assertSame(['raw' => 2, 'history' => 1], $this->left('40000003'), 'not purged, so not touched');
    }
}
