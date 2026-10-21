<?php

namespace Tests\Feature\Ingest;

use App\Ingest\CarLotContract;
use App\Ingest\IngestConsumer;
use Database\Factories\LotFactory;
use Database\Factories\ParseFilterFactory;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseOnce;
use Tests\TestCase;

/**
 * collector.ingest_lots → lots / lot_inspections / lot_changes / filter_skip_log
 * on the real MySQL test database (docs/adr/0003, 0004).
 */
final class IngestConsumerTest extends TestCase
{
    use RefreshesDatabaseOnce {
        beginDatabaseTransaction as beginTestTransactions;
    }

    /** The buffer lives on the collector connection; roll it back too. */
    protected array $connectionsToTransact = ['mysql', 'collector'];

    private const NOW = '2026-10-21 10:00:00';

    /**
     * The buffer table belongs to the collector; its DDL comes from the file the
     * collector applies. Created here — after migrations, before the test
     * transactions open — because DDL would commit an open transaction.
     */
    public function beginDatabaseTransaction(): void
    {
        if (! DB::connection('collector')->getSchemaBuilder()->hasTable('ingest_lots')) {
            DB::connection('collector')->unprepared((string) file_get_contents(base_path('../collector/migrations/001_ingest_lots.sql')));
        }
        $this->beginTestTransactions();
    }

    /** @return array<string, mixed> */
    private static function lot(array $over = []): array
    {
        $lot = json_decode((string) file_get_contents(base_path('../contracts/fixtures/carlot.v1/valid/full.json')), true);

        return array_replace_recursive($lot, $over);
    }

    /** @param array<string, mixed> $lot */
    private function buffer(array $lot, ?string $payload = null): int
    {
        $payload ??= json_encode($lot, JSON_UNESCAPED_UNICODE);

        return (int) DB::connection('collector')->table('ingest_lots')->insertGetId([
            'run_id' => $lot['run_id'] ?? null,
            'source' => $lot['source'] ?? 'encar',
            'source_id' => $lot['source_id'] ?? '?',
            'contract' => 'carlot/1',
            'payload' => $payload,
            'content_hash' => hash('sha256', $payload),
        ]);
    }

    private function consume(int $batch = 500): \App\Ingest\IngestResult
    {
        return (new IngestConsumer(CarLotContract::fromConfig()))->consumeBatch($batch, self::NOW);
    }

    /** @return list<object> */
    private function changes(string $lotId): array
    {
        return DB::table('lot_changes')->where('lot_id', $lotId)->orderBy('id')->get()->all();
    }

    public function test_new_lot_is_written_with_its_inspection(): void
    {
        $this->buffer(self::lot());
        $result = $this->consume();

        $this->assertSame(1, $result->consumed);
        $this->assertSame(1, $result->inserted);
        $lot = DB::table('lots')->find('39847215');
        $this->assertSame(18_900_000, (int) $lot->price);
        $this->assertSame(1, (int) $lot->is_active);
        $this->assertSame('KMHL341CBMA123456', $lot->vin);
        $this->assertSame('001,002', $lot->photo_codes);
        $this->assertSame(self::NOW, $lot->parsed_at);
        $this->assertSame('2026-10-21 09:14:00', $lot->history_checked_at);
        $this->assertNotNull($lot->ingest_hash);
        $this->assertSame(45_100, (int) DB::table('lot_inspections')->where('lot_id', '39847215')->value('inspection_mileage'));
        $this->assertSame([], $this->changes('39847215'), 'a new lot has no change entry');
        $this->assertGreaterThan(0, (int) DB::table('ingest_offsets')->where('consumer', 'core')->value('last_id'));
    }

    public function test_empty_buffer_consumes_nothing(): void
    {
        $this->assertSame(0, $this->consume()->consumed);
    }

    public function test_same_content_again_only_marks_the_lot_seen(): void
    {
        $this->buffer(self::lot());
        $this->consume();
        DB::table('lots')->where('id', '39847215')->update(['parsed_at' => '2026-10-01 00:00:00', 'make_en' => 'Hyundai']);

        $this->buffer(self::lot());
        $result = $this->consume();

        $this->assertSame(1, $result->unchanged);
        $lot = DB::table('lots')->find('39847215');
        $this->assertSame(self::NOW, $lot->parsed_at);
        $this->assertSame('Hyundai', $lot->make_en);
        $this->assertSame([], $this->changes('39847215'));
    }

    public function test_update_keeps_stored_values_where_the_source_sends_null(): void
    {
        LotFactory::new()->create([
            'id' => '39847215', 'price' => 19_500_000, 'vin' => 'KMHOLDVIN00000001', 'make_en' => 'Hyundai',
            'model_group_en' => 'Sonata', 'color' => 'black', 'is_active' => true,
        ]);
        $this->buffer(self::lot(['vehicle' => ['vin' => null, 'color' => null]]));

        $result = $this->consume();

        $this->assertSame(1, $result->updated);
        $lot = DB::table('lots')->find('39847215');
        $this->assertSame(18_900_000, (int) $lot->price, 'price always takes the source value');
        $this->assertSame('KMHOLDVIN00000001', $lot->vin, 'null VIN keeps the stored one');
        $this->assertSame('black', $lot->color);
        $this->assertSame('Hyundai', $lot->make_en, 'normalized columns are never touched');
        $this->assertSame('Sonata', $lot->model_group_en);

        $changes = $this->changes('39847215');
        $this->assertCount(1, $changes);
        $this->assertSame('update', $changes[0]->event);
        $this->assertEquals(['old' => 19_500_000, 'new' => 18_900_000], json_decode($changes[0]->changes, true)['price']);
    }

    public function test_photos_are_kept_when_the_gallery_was_not_seen(): void
    {
        $this->buffer(self::lot());
        $this->consume();
        $this->buffer(self::lot(['listing' => ['photos' => [], 'price' => ['amount' => 18_000_000]]]));
        $this->consume();

        $this->assertSame('001,002', DB::table('lots')->where('id', '39847215')->value('photo_codes'));
    }

    public function test_skip_rule_on_new_lot_stores_only_the_skip_log(): void
    {
        $rule = ParseFilterFactory::new()->create(['name' => 'test_insurance_rule', 'field' => 'insurance_count', 'operator' => 'gte', 'value' => '4', 'action' => 'skip']);
        $this->buffer(self::lot(['history' => ['insurance_count' => 5], 'selection' => ['passed' => false, 'phase' => 'pre', 'action' => 'skip', 'rule_ids' => [$rule->id]]]));

        $result = $this->consume();

        $this->assertSame(1, $result->skipped);
        $this->assertNull(DB::table('lots')->find('39847215'));
        $log = DB::table('filter_skip_log')->where('source_id', '39847215')->first();
        $this->assertSame('test_insurance_rule', $log->rule_name);
        $this->assertSame('insurance_count', $log->field_name);
        $this->assertSame('5', $log->field_value);
        $this->assertSame('skip', $log->action);
    }

    public function test_rejected_lot_does_not_flap_across_runs(): void
    {
        $rule = ParseFilterFactory::new()->create(['name' => 'test_insurance_rule', 'field' => 'insurance_count', 'action' => 'skip']);
        LotFactory::new()->create(['id' => '39847215', 'is_active' => true]);
        $rejected = self::lot(['history' => ['insurance_count' => 5], 'selection' => ['passed' => false, 'phase' => 'pre', 'action' => 'skip', 'rule_ids' => [$rule->id]]]);

        foreach (range(1, 3) as $run) {
            $this->buffer($rejected);
            $this->consume();
        }

        $this->assertSame(0, (int) DB::table('lots')->where('id', '39847215')->value('is_active'));
        $events = array_map(fn ($c) => $c->event, $this->changes('39847215'));
        $this->assertSame(['deactivated_filter'], $events, 'one deactivation, no relisted/deactivated pairs');
        $this->assertSame(3, (int) DB::table('filter_skip_log')->where('source_id', '39847215')->value('skip_count'));
    }

    public function test_mark_inactive_stores_the_lot_inactive(): void
    {
        $rule = ParseFilterFactory::new()->create(['name' => 'test_mark_inactive_rule', 'field' => 'year', 'action' => 'mark_inactive']);
        $this->buffer(self::lot(['selection' => ['passed' => false, 'phase' => 'post', 'action' => 'mark_inactive', 'rule_ids' => [$rule->id]]]));

        $this->consume();

        $this->assertSame(0, (int) DB::table('lots')->where('id', '39847215')->value('is_active'));
        $this->assertSame('mark_inactive', DB::table('filter_skip_log')->where('source_id', '39847215')->value('action'));
    }

    public function test_delisted_lot_seen_again_is_relisted_even_with_the_same_content(): void
    {
        $this->buffer(self::lot());
        $this->consume();
        DB::table('lots')->where('id', '39847215')->update(['is_active' => 0]); // lots:lifecycle delisted it

        $this->buffer(self::lot());
        $this->consume();

        $this->assertSame(1, (int) DB::table('lots')->where('id', '39847215')->value('is_active'));
        $this->assertSame(['relisted'], array_map(fn ($c) => $c->event, $this->changes('39847215')));
    }

    public function test_invalid_and_unsupported_records_are_rejected_and_the_batch_goes_on(): void
    {
        // Malformed JSON cannot reach the buffer at all: ingest_lots.payload is a JSON column.
        $this->buffer(['source' => 'encar', 'source_id' => 'bad'], '{"source":"encar","source_id":"bad"}');
        $this->buffer(self::lot(['source_id' => '7', 'url' => 'https://fem.encar.com/cars/detail/7', 'listing' => ['price' => ['amount' => 5, 'currency' => 'JPY']]]));
        $this->buffer(self::lot());

        $result = $this->consume();

        $this->assertSame(3, $result->consumed);
        $this->assertSame(2, $result->rejected);
        $this->assertSame(1, $result->inserted);
        $this->assertSame(2, DB::table('ingest_rejections')->count());
        $this->assertStringContainsString('currency JPY', DB::table('ingest_rejections')->where('source_id', '7')->value('errors'));
        $this->assertSame(0, $this->consume()->consumed, 'offset moved past the rejected rows');
    }

    public function test_last_record_of_a_lot_in_a_batch_wins(): void
    {
        $this->buffer(self::lot(['listing' => ['price' => ['amount' => 1_000_000]]]));
        $this->buffer(self::lot(['listing' => ['price' => ['amount' => 2_000_000]]]));

        $this->consume();

        $this->assertSame(2_000_000, (int) DB::table('lots')->where('id', '39847215')->value('price'));
    }

    public function test_drain_reads_batch_by_batch_and_the_command_reports(): void
    {
        foreach (range(1, 5) as $i) {
            $this->buffer(self::lot(['source_id' => (string) (100 + $i), 'url' => "https://fem.encar.com/cars/detail/{$i}"]));
        }

        $total = (new IngestConsumer(CarLotContract::fromConfig()))->drain(batchSize: 2);
        $this->assertSame(5, $total->consumed);
        $this->assertSame(5, $total->inserted);

        $this->buffer(self::lot());
        $this->artisan('ingest:consume', ['--batch' => 10])->expectsOutputToContain('Consumed')->assertSuccessful();
        $this->assertNotNull(DB::table('lots')->find('39847215'));
    }

    public function test_max_batches_stops_early(): void
    {
        foreach (range(1, 3) as $i) {
            $this->buffer(self::lot(['source_id' => (string) (200 + $i)]));
        }

        $total = (new IngestConsumer(CarLotContract::fromConfig()))->drain(batchSize: 1, maxBatches: 2);
        $this->assertSame(2, $total->consumed);
    }
}
