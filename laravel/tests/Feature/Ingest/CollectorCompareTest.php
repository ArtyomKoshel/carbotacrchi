<?php

namespace Tests\Feature\Ingest;

use App\Ingest\ShadowComparison;
use Database\Factories\LotFactory;
use Database\Factories\ParseJobFactory;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseOnce;
use Tests\TestCase;

/** Shadow mode: a collector run against the lots the old parser wrote. */
final class CollectorCompareTest extends TestCase
{
    use RefreshesDatabaseOnce {
        beginDatabaseTransaction as beginTestTransactions;
    }

    protected array $connectionsToTransact = ['mysql', 'collector'];

    public function beginDatabaseTransaction(): void
    {
        if (! DB::connection('collector')->getSchemaBuilder()->hasTable('ingest_lots')) {
            DB::connection('collector')->unprepared((string) file_get_contents(base_path('../collector/migrations/001_ingest_lots.sql')));
        }
        $this->beginTestTransactions();
    }

    /** @return array<string, mixed> */
    private static function record(string $id, array $over = []): array
    {
        $lot = json_decode((string) file_get_contents(base_path('../contracts/fixtures/carlot.v1/valid/full.json')), true);
        $lot['source_id'] = $id;

        return array_replace_recursive($lot, $over);
    }

    private function buffer(int $runId, array $lot): void
    {
        $payload = json_encode($lot, JSON_UNESCAPED_UNICODE);
        DB::connection('collector')->table('ingest_lots')->insert([
            'run_id' => $runId, 'source' => 'encar', 'source_id' => $lot['source_id'], 'contract' => 'carlot/1',
            'payload' => $payload, 'content_hash' => hash('sha256', $payload),
        ]);
    }

    public function test_counts_field_differences_and_coverage(): void
    {
        $job = ParseJobFactory::new()->create(['status' => 'done', 'filters' => ['engine' => 'collector']]);
        // same lot, same values except price; is_active the same
        LotFactory::new()->create([
            'id' => '1', 'price' => 19_000_000, 'mileage' => 45_210, 'year' => 2021, 'make' => '현대', 'model' => '쏘나타 (DN8)',
            'trim' => '프레스티지', 'vin' => 'KMHL341CBMA123456', 'is_active' => true,
        ]);
        // active in lots, not seen by the collector
        LotFactory::new()->create(['id' => '9', 'is_active' => true]);
        $this->buffer($job->id, self::record('1'));
        $this->buffer($job->id, self::record('2')); // only in the collector
        $this->buffer($job->id, self::record('3', ['selection' => ['passed' => false, 'phase' => 'pre', 'action' => 'skip', 'rule_ids' => [1]]]));

        $report = (new ShadowComparison)->compare($job->id);

        $this->assertSame(3, $report['records']);
        $this->assertSame(1, $report['compared']);
        $this->assertSame(1, $report['only_collector'], 'a skipped new lot is absent on both sides');
        $this->assertSame(1, $report['only_parser_active']);
        $this->assertSame(1, $report['fields']['price']['diff']);
        $this->assertSame(100.0, $report['fields']['price']['rate']);
        $this->assertSame(['lot_id' => '1', 'parser' => 19_000_000, 'collector' => 18_900_000], $report['fields']['price']['sample'][0]);
        $this->assertSame(0, $report['fields']['mileage']['diff']);
        $this->assertSame(0, $report['fields']['is_active']['diff']);
    }

    public function test_command_reports_the_latest_collector_run(): void
    {
        $this->artisan('collector:compare')->expectsOutputToContain('No finished collector run')->assertFailed();

        $job = ParseJobFactory::new()->create(['status' => 'done', 'filters' => ['engine' => 'collector']]);
        $this->buffer($job->id, self::record('1'));
        LotFactory::new()->create(['id' => '1', 'price' => 18_900_000]);

        $this->artisan('collector:compare')->expectsOutputToContain("Run #{$job->id}: 1 records, 1 compared")->assertSuccessful();
        $this->artisan('collector:compare', ['--job' => $job->id, '--json' => true])->expectsOutputToContain('"compared": 1')->assertSuccessful();
    }
}
