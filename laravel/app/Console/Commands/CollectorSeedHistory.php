<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time start of the collector's history store (collector.lot_history) from what the
 * old parser left in `lots`. Without it the collector's first full run fetches the
 * record and inspection of every lot (~3 requests a lot, a day-long run); with it the
 * first run already rechecks only the oldest third, like every later run.
 *
 * Rows the collector wrote itself are never replaced (INSERT IGNORE). The format is the
 * collector's own (collector/src/collector/adapters/encar/pipeline.py history_snapshot):
 * keep the field lists below in step with HISTORY_FIELDS, HISTORY_RAW_KEYS, DRIVE_* and
 * LISTING_KEYS there — CollectorSeedHistoryTest checks it.
 */
class CollectorSeedHistory extends Command
{
    public const HISTORY_FIELDS = [
        'owners_count', 'insurance_count', 'has_accident', 'flood_history', 'total_loss_history',
        'lien_status', 'seizure_status', 'repair_cost', 'first_reg_date', 'vin',
    ];

    public const HISTORY_RAW_KEYS = ['engine_code', 'warranty_type', 'recall', 'recall_status', 'car_state', 'mechanical_issues'];

    public const DRIVE_FIELDS = ['drive_type'];

    public const DRIVE_RAW_KEYS = ['selling_point'];

    public const LISTING_KEYS = ['mileage', 'price', 'plate_number'];

    private const BOOLEANS = ['has_accident', 'flood_history', 'total_loss_history'];

    protected $signature = 'collector:seed-history
        {--source=encar : Platform}
        {--chunk=2000 : Lots per insert}
        {--dry-run : Count only, write nothing}';

    protected $description = "Start the collector's history store from the history already in lots (run once, before its first full run)";

    public function handle(): int
    {
        $source = (string) $this->option('source');
        $chunk = max(100, (int) $this->option('chunk'));
        $dry = (bool) $this->option('dry-run');
        $collector = DB::connection('collector');
        $columns = ['id', 'history_checked_at', 'raw_data', ...self::HISTORY_FIELDS, ...self::DRIVE_FIELDS, ...self::LISTING_KEYS];

        $seen = $written = 0;
        DB::table('lots')->where('source', $source)->whereNotNull('history_checked_at')
            ->select(array_values(array_unique($columns)))
            ->chunkById($chunk, function ($lots) use ($collector, $source, $dry, &$seen, &$written) {
                $rows = $lots->map(fn (object $lot) => self::row($source, $lot))->all();
                $seen += count($rows);
                if (! $dry) {
                    $written += $collector->table('lot_history')->insertOrIgnore($rows);
                }
            }, 'id');

        $dry
            ? $this->info("{$source}: {$seen} lots have a history to seed (dry run, nothing written).")
            : $this->info("{$source}: {$written} histories seeded, ".($seen - $written).' already in the collector store.');

        return self::SUCCESS;
    }

    /** @return array{source: string, lot_id: string, checked_at: string, condition_tags: string, fields: string} */
    public static function row(string $source, object $lot): array
    {
        $raw = $lot->raw_data;
        for ($i = 0; is_string($raw) && $i < 2; $i++) {
            $raw = json_decode($raw, true); // a JSON column; some old rows hold it encoded twice
        }
        $raw = is_array($raw) ? $raw : [];
        $fields = [];
        foreach ([...self::HISTORY_FIELDS, ...self::DRIVE_FIELDS] as $f) {
            $v = $lot->{$f};
            if ($v !== null) {
                $fields[$f] = in_array($f, self::BOOLEANS, true) ? (bool) $v : (is_numeric($v) && ! in_array($f, ['vin', 'first_reg_date'], true) ? (int) $v : $v);
            }
        }
        foreach ([...self::HISTORY_RAW_KEYS, ...self::DRIVE_RAW_KEYS] as $k) {
            if (array_key_exists($k, $raw) && $raw[$k] !== null) {
                $fields[$k] = $raw[$k];
            }
        }
        $fields['listing'] = array_filter(
            ['mileage' => $lot->mileage === null ? null : (int) $lot->mileage, 'price' => $lot->price === null ? null : (int) $lot->price, 'plate_number' => $lot->plate_number],
            fn ($v) => $v !== null,
        );
        $condition = array_values(array_map('strval', (array) ($raw['condition'] ?? [])));
        sort($condition);

        return [
            'source' => $source,
            'lot_id' => (string) $lot->id,
            'checked_at' => substr((string) $lot->history_checked_at, 0, 19),
            'condition_tags' => json_encode($condition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }
}
