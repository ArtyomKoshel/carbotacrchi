<?php

namespace Tests\Unit\Ingest;

use App\Ingest\LotChangeDetector;
use App\Ingest\LotRowMapper;
use App\Ingest\LotUpsert;
use App\Ingest\UnsupportedRecord;
use PHPUnit\Framework\TestCase;

final class LotRowMapperTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(string $name = 'full'): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 4)."/contracts/fixtures/carlot.v1/valid/{$name}.json"), true);
    }

    /** @param list<string> $codes */
    private static function mask(array $codes): ?string
    {
        return $codes === [] ? null : (string) count($codes);
    }

    public function test_encar_keeps_its_id_other_platforms_are_prefixed(): void
    {
        $this->assertSame('39847215', LotRowMapper::lotId('encar', '39847215'));
        $this->assertSame('kcar:A-1', LotRowMapper::lotId('kcar', 'A-1'));
    }

    public function test_full_record_maps_to_lot_columns(): void
    {
        $row = LotRowMapper::lotRow(self::fixture(), self::mask(...));

        $this->assertSame('39847215', $row['id']);
        $this->assertSame(18_900_000, $row['price']);
        $this->assertSame('KRW', $row['currency']);
        $this->assertSame(45_210, $row['mileage']);
        $this->assertSame(2.0, $row['engine_volume']);
        $this->assertSame(0, $row['has_accident']);
        $this->assertSame(0, $row['flood_history']);
        $this->assertSame(2, $row['owners_count']);
        $this->assertSame(450_000, $row['repair_cost']);
        $this->assertSame(32_000_000, $row['retail_value']);
        $this->assertSame('clean', $row['lien_status']);
        $this->assertSame('2026-09-14 12:00:00', $row['listed_at']);
        $this->assertSame('2021-03-02', $row['first_reg_date']);
        $this->assertSame('["001","014"]', $row['options']);
        $this->assertNull($row['paid_options']);
        $this->assertSame('2', $row['options_mask']);
        $this->assertSame('https://fem.encar.com/cars/detail/39847215', $row['lot_url']);
        $this->assertSame(['ad_type' => 'normal', 'condition' => ['Record', 'Inspection']], json_decode($row['raw_data'], true));
        // Encar gallery template → compact photo columns
        $this->assertSame(8, $row['photo_dir']);
        $this->assertSame(39847215, $row['photo_base_id']);
        $this->assertSame('001,002', $row['photo_codes']);
        $this->assertNull($row['photo_urls']);
        $this->assertSame('2026-10-21 09:14:00', LotRowMapper::historyCheckedAt(self::fixture()));
    }

    public function test_minimal_record_leaves_optional_columns_null(): void
    {
        $row = LotRowMapper::lotRow(self::fixture('minimal'), self::mask(...));

        $this->assertNull($row['vin']);
        $this->assertNull($row['raw_data']);
        $this->assertNull($row['options']);
        $this->assertNull($row['options_mask']);
        $this->assertNull($row['photo_codes'], 'no photos = keep what is stored');
        $this->assertNull(LotRowMapper::historyCheckedAt(self::fixture('minimal')));
        $this->assertNull(LotRowMapper::inspectionRow(self::fixture('minimal')));
    }

    public function test_listing_time_is_kept_in_korean_time_as_the_old_parser_stored_it(): void
    {
        $lot = self::fixture('minimal');
        foreach (['2026-09-14T00:00:00+09:00', '2026-09-13T15:00:00Z'] as $sameMoment) {
            $lot['listing']['listed_at'] = $sameMoment;
            // Encar gives the listing date only; in UTC its Korean midnight is the day before.
            $this->assertSame('2026-09-14 00:00:00', LotRowMapper::lotRow($lot, self::mask(...))['listed_at']);
        }
    }

    public function test_photos_outside_the_template_are_stored_verbatim(): void
    {
        $lot = self::fixture('minimal');
        $lot['listing']['photos'] = ['https://img.example.com/1.jpg'];
        $row = LotRowMapper::lotRow($lot, self::mask(...));
        $this->assertSame('', $row['photo_codes']);
        $this->assertSame(['https://img.example.com/1.jpg'], json_decode($row['photo_urls'], true));
    }

    public function test_unknown_currency_is_refused(): void
    {
        $lot = self::fixture('minimal');
        $lot['listing']['price'] = ['amount' => 100, 'currency' => 'JPY'];
        $this->expectException(UnsupportedRecord::class);
        LotRowMapper::lotRow($lot, self::mask(...));
    }

    public function test_che168_lot_keeps_yuan_and_china_time(): void
    {
        $lot = self::fixture('minimal');
        $lot['source'] = 'che168';
        $lot['source_id'] = '60321227';
        $lot['listing']['price'] = ['amount' => 189_800, 'currency' => 'CNY'];
        $lot['listing']['retail_value'] = ['amount' => 545_000, 'currency' => 'CNY'];
        $lot['listing']['listed_at'] = '2026-10-29T00:00:00+08:00';
        $row = LotRowMapper::lotRow($lot, self::mask(...));

        $this->assertSame('che168:60321227', $row['id']);
        $this->assertSame([189_800, 'CNY', 545_000], [$row['price'], $row['currency'], $row['retail_value']]);
        $this->assertSame('2026-10-29 00:00:00', $row['listed_at']);
    }

    public function test_an_amount_in_another_currency_than_the_price_is_refused(): void
    {
        $lot = self::fixture('minimal');
        $lot['listing']['price'] = ['amount' => 189_800, 'currency' => 'CNY'];
        $lot['listing']['retail_value'] = ['amount' => 32_000_000, 'currency' => 'KRW'];
        $this->expectException(UnsupportedRecord::class);
        LotRowMapper::lotRow($lot, self::mask(...));
    }

    public function test_inspection_row(): void
    {
        $row = LotRowMapper::inspectionRow(self::fixture());

        $this->assertSame('39847215', $row['lot_id']);
        $this->assertSame('2026-09-10', $row['inspection_date']);
        $this->assertSame(45_100, $row['inspection_mileage']);
        $this->assertSame(0, $row['has_accident']);
        $this->assertSame(['inspector_note' => 'OK'], json_decode($row['details'], true));
    }

    public function test_every_mapped_column_has_an_upsert_rule(): void
    {
        $insertOnly = ['id', 'source', 'photo_codes', 'created_at'];
        $ruled = [...LotUpsert::LOT_OVERWRITE, ...LotUpsert::LOT_COALESCE, ...LotUpsert::PHOTO_GROUP, ...$insertOnly];
        $columns = array_keys(LotRowMapper::lotRow(self::fixture(), self::mask(...)) + ['is_active' => 1, 'history_checked_at' => null, 'ingest_hash' => '']);

        $this->assertSame([], array_values(array_diff($columns, $ruled)), 'column without an ON DUPLICATE KEY rule');
        $this->assertSame([], array_values(array_diff(LotChangeDetector::TRACKED, $columns)), 'tracked field not mapped');

        $inspection = array_keys(LotRowMapper::inspectionRow(self::fixture()));
        $inspectionRuled = [...LotUpsert::INSPECTION_COALESCE, 'lot_id', 'source', 'details'];
        $this->assertSame([], array_values(array_diff($inspection, $inspectionRuled)));
    }
}
