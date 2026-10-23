<?php

namespace App\Ingest;

use Illuminate\Database\ConnectionInterface;

/**
 * INSERT … ON DUPLICATE KEY UPDATE for `lots` and `lot_inspections` with the
 * same per-column rules as the old parser (parser/repository.py upsert_batch,
 * upsert_inspection): a source value overwrites, or — for COALESCE columns —
 * only replaces what is stored when it is not null. Columns that normalization
 * fills (make_en, model_group_en, …) are never touched.
 */
final class LotUpsert
{
    /** Always take the incoming value. */
    public const LOT_OVERWRITE = [
        'price', 'mileage', 'make', 'model', 'year', 'location', 'lot_url', 'raw_data', 'is_active',
        'parsed_at', 'updated_at', 'ingest_hash',
    ];

    /** Keep the stored value when the incoming one is null. */
    public const LOT_COALESCE = [
        'model_en', 'model_group', 'vin', 'body_type', 'transmission', 'fuel', 'drive_type', 'engine_volume',
        'badge_group', 'trim_en', 'badge', 'options', 'options_mask',
        'paid_options', 'image_url', 'lien_status', 'seizure_status', 'has_accident', 'flood_history',
        'total_loss_history', 'owners_count', 'insurance_count', 'retail_value', 'repair_cost',
        'plate_number', 'first_reg_date', 'listed_at', 'sell_type', 'sell_type_raw', 'seat_count',
        'history_checked_at',
    ];

    /**
     * Filled from the source only while empty. Normalization rewrites them in place
     * (lots:normalize-from-catalog translates the color, the grade extractor sets the trim);
     * the old parser put the raw value back every run and normalization fixed it again an
     * hour later: ~16 000 junk lot_changes per run and Korean values in the filters meanwhile.
     */
    public const LOT_KEEP = ['color', 'seat_color', 'trim'];

    /** photo_codes non-null = the gallery was seen: replace the whole group; otherwise keep it. */
    public const PHOTO_GROUP = ['photo_dir', 'photo_base_id', 'photo_ext_upper', 'photo_urls'];

    public const INSPECTION_COALESCE = [
        'cert_no', 'inspection_date', 'valid_from', 'valid_until', 'report_url', 'first_registration',
        'inspection_mileage', 'insurance_fee', 'has_accident', 'has_outer_damage', 'has_flood', 'has_fire',
        'has_tuning', 'accident_detail', 'outer_detail',
    ];

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @param list<array<string, mixed>> $rows rows with identical keys */
    public function lots(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $updates = [];
        foreach (self::LOT_OVERWRITE as $c) {
            $updates[] = "`{$c}` = new.`{$c}`";
        }
        foreach (self::LOT_COALESCE as $c) {
            $updates[] = "`{$c}` = COALESCE(new.`{$c}`, lots.`{$c}`)";
        }
        foreach (self::LOT_KEEP as $c) {
            $updates[] = "`{$c}` = COALESCE(lots.`{$c}`, new.`{$c}`)";
        }
        foreach (self::PHOTO_GROUP as $c) {
            $updates[] = "`{$c}` = IF(new.photo_codes IS NULL, lots.`{$c}`, new.`{$c}`)";
        }
        // Last, so the IF()s above still see the incoming photo_codes.
        $updates[] = '`photo_codes` = COALESCE(new.photo_codes, lots.photo_codes)';

        $this->insert('lots', $rows, $updates);
    }

    /** @param list<array<string, mixed>> $rows */
    public function inspections(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $updates = ['`source` = new.`source`', '`details` = new.`details`', '`updated_at` = new.`updated_at`'];
        foreach (self::INSPECTION_COALESCE as $c) {
            $updates[] = "`{$c}` = COALESCE(new.`{$c}`, lot_inspections.`{$c}`)";
        }
        $this->insert('lot_inspections', $rows, $updates);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $updates
     */
    private function insert(string $table, array $rows, array $updates): void
    {
        $columns = array_keys($rows[0]);
        $placeholders = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columns as $c) {
                $bindings[] = $row[$c];
            }
        }
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES %s AS new ON DUPLICATE KEY UPDATE %s',
            $table,
            implode(', ', array_map(fn (string $c) => "`{$c}`", $columns)),
            implode(', ', array_fill(0, count($rows), $placeholders)),
            implode(', ', $updates),
        );
        $this->db->statement($sql, $bindings);
    }
}
