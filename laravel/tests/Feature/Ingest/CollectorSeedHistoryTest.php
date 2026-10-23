<?php

namespace Tests\Feature\Ingest;

use App\Console\Commands\CollectorSeedHistory;
use Database\Factories\LotFactory;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseOnce;
use Tests\TestCase;

/** collector:seed-history writes the collector's history rows from lots, never over its own. */
final class CollectorSeedHistoryTest extends TestCase
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

    private function history(string $id): ?object
    {
        return DB::connection('collector')->table('lot_history')->where('source', 'encar')->where('lot_id', $id)->first();
    }

    public function test_a_lot_history_becomes_a_collector_row(): void
    {
        LotFactory::new()->create([
            'id' => '41536978', 'source' => 'encar', 'history_checked_at' => '2026-10-20 23:10:05',
            'owners_count' => 2, 'insurance_count' => 1, 'has_accident' => 1, 'flood_history' => 0, 'total_loss_history' => null,
            'lien_status' => 'clean', 'seizure_status' => 'clean', 'repair_cost' => 450000, 'first_reg_date' => '2021-03-02',
            'vin' => 'KMHL341CBMA123456', 'drive_type' => 'awd', 'mileage' => 45210, 'price' => 18900000, 'plate_number' => '12가3456',
            'raw_data' => ['condition' => ['Record', 'Inspection'], 'engine_code' => 'G4KN', 'selling_point' => 'AWD', 'other' => 1],
        ]);
        LotFactory::new()->create(['id' => '41536979', 'source' => 'encar', 'history_checked_at' => null]);

        $this->artisan('collector:seed-history')->expectsOutputToContain('1 histories seeded')->assertSuccessful();

        $row = $this->history('41536978');
        $this->assertSame('2026-10-20 23:10:05', $row->checked_at);
        $this->assertSame(['Inspection', 'Record'], json_decode($row->condition_tags, true), 'sorted, as the collector compares them');
        $this->assertEquals([ // MySQL reorders JSON keys
            'owners_count' => 2, 'insurance_count' => 1, 'has_accident' => true, 'flood_history' => false,
            'lien_status' => 'clean', 'seizure_status' => 'clean', 'repair_cost' => 450000, 'first_reg_date' => '2021-03-02',
            'vin' => 'KMHL341CBMA123456', 'drive_type' => 'awd', 'engine_code' => 'G4KN', 'selling_point' => 'AWD',
            'listing' => ['mileage' => 45210, 'price' => 18900000, 'plate_number' => '12가3456'],
        ], json_decode($row->fields, true));
        $this->assertNull($this->history('41536979'), 'never checked: the collector fetches it');
    }

    public function test_the_collector_own_rows_are_kept_and_dry_run_writes_nothing(): void
    {
        LotFactory::new()->create(['id' => '1', 'source' => 'encar', 'history_checked_at' => '2026-10-01 00:00:00', 'raw_data' => []]);
        LotFactory::new()->create(['id' => '2', 'source' => 'encar', 'history_checked_at' => '2026-10-01 00:00:00', 'raw_data' => []]);
        DB::connection('collector')->table('lot_history')->insert([
            'source' => 'encar', 'lot_id' => '1', 'checked_at' => '2026-10-23 10:00:00', 'condition_tags' => '[]', 'fields' => '{"owners_count": 3}',
        ]);

        $this->artisan('collector:seed-history --dry-run')->expectsOutputToContain('2 lots have a history to seed')->assertSuccessful();
        $this->assertNull($this->history('2'));

        $this->artisan('collector:seed-history')->expectsOutputToContain('1 histories seeded, 1 already in the collector store')->assertSuccessful();
        $this->assertSame('2026-10-23 10:00:00', $this->history('1')->checked_at, 'fresher row of the collector stays');
        $this->assertNotNull($this->history('2'));
    }

    public function test_field_lists_match_the_collector_pipeline(): void
    {
        $py = (string) file_get_contents(base_path('../collector/src/collector/adapters/encar/pipeline.py'));
        $tuple = function (string $name) use ($py): array {
            $this->assertMatchesRegularExpression("/^{$name}: Final = \\(/m", $py);
            preg_match("/^{$name}: Final = \\((.*?)\\)/ms", $py, $m);
            preg_match_all('/"([a-z_]+)"/', $m[1], $names);

            return $names[1];
        };

        $this->assertSame($tuple('HISTORY_FIELDS'), CollectorSeedHistory::HISTORY_FIELDS);
        $this->assertSame($tuple('HISTORY_RAW_KEYS'), CollectorSeedHistory::HISTORY_RAW_KEYS);
        $this->assertSame($tuple('DRIVE_FIELDS'), CollectorSeedHistory::DRIVE_FIELDS);
        $this->assertSame($tuple('DRIVE_RAW_KEYS'), CollectorSeedHistory::DRIVE_RAW_KEYS);
        $this->assertSame($tuple('LISTING_KEYS'), CollectorSeedHistory::LISTING_KEYS);
    }
}
