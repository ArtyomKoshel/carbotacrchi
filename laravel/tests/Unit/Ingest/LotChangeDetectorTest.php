<?php

namespace Tests\Unit\Ingest;

use App\Ingest\LotChangeDetector;
use PHPUnit\Framework\TestCase;

final class LotChangeDetectorTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function stored(array $over = []): array
    {
        return [
            'trim' => '프레스티지', 'price' => '18900000', 'mileage' => 45210, 'color' => 'white',
            'lien_status' => 'clean', 'seizure_status' => 'clean', 'has_accident' => 0, 'flood_history' => 0,
            'total_loss_history' => null, 'owners_count' => 2, 'insurance_count' => 1, 'sell_type' => 'sale',
            'options' => '["001", "014"]', 'is_active' => 1, ...$over,
        ];
    }

    public function test_new_lot_has_no_change_entry(): void
    {
        $this->assertNull(LotChangeDetector::detect(null, ['price' => 1]));
    }

    public function test_same_values_in_other_representations_are_not_a_change(): void
    {
        $incoming = [...self::stored(), 'price' => 18_900_000, 'has_accident' => false, 'options' => '["001","014"]'];
        $this->assertNull(LotChangeDetector::detect(self::stored(), $incoming));
    }

    public function test_null_incoming_value_is_not_a_change(): void
    {
        $this->assertNull(LotChangeDetector::detect(self::stored(), [...self::stored(), 'color' => null, 'trim' => null]));
    }

    public function test_price_drop_is_an_update_in_the_old_format(): void
    {
        $change = LotChangeDetector::detect(self::stored(), [...self::stored(), 'price' => 18_500_000]);
        $this->assertSame('update', $change['event']);
        $this->assertSame(['price' => ['old' => 18_900_000, 'new' => 18_500_000]], $change['changes']);
    }

    public function test_activity_changes_name_the_event(): void
    {
        $relist = LotChangeDetector::detect(self::stored(['is_active' => 0]), [...self::stored(), 'is_active' => 1]);
        $this->assertSame('relisted', $relist['event']);

        $off = LotChangeDetector::detect(self::stored(), [...self::stored(), 'is_active' => 0]);
        $this->assertSame('deactivated_filter', $off['event']);
    }

    public function test_options_order_matters_like_before(): void
    {
        $change = LotChangeDetector::detect(self::stored(), [...self::stored(), 'options' => '["014","001"]']);
        $this->assertSame(['001', '014'], $change['changes']['options']['old']);
        $this->assertSame(['014', '001'], $change['changes']['options']['new']);
    }

    public function test_untracked_fields_are_ignored(): void
    {
        $this->assertNull(LotChangeDetector::detect(self::stored(), [...self::stored(), 'vin' => 'X', 'location' => 'Seoul']));
    }

    /** A colour in Korean is the English one stored before; Encar's "no complectation" is none. */
    public function test_a_language_or_a_placeholder_is_not_a_change(): void
    {
        $this->assertNull(LotChangeDetector::detect(self::stored(['color' => 'Silver', 'trim' => null]),
            [...self::stored(), 'color' => '은회색', 'trim' => '(세부등급 없음)']));

        $change = LotChangeDetector::detect(self::stored(['color' => 'Silver']), [...self::stored(), 'color' => '하늘색']);
        $this->assertSame(['color' => ['old' => 'Silver', 'new' => 'Blue']], $change['changes']);
    }
}
