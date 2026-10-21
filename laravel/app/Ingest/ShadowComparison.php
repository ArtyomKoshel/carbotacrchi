<?php

namespace App\Ingest;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Shadow mode check (docs/adr/0007): a collector run's records, mapped exactly as
 * the ingest would map them, against what the old parser wrote to `lots`.
 *
 * Differences are counted per field. Some are expected — the two runs do not
 * see a listing at the same moment (price changes) and the collector fixes known
 * junk (VINs, engine volumes) — so the runbook judges rates, not zero.
 */
final class ShadowComparison
{
    /** Columns compared; the rest are either identical by construction or not filled by either side. */
    public const FIELDS = [
        'price', 'mileage', 'year', 'make', 'model', 'trim', 'fuel', 'transmission', 'drive_type', 'body_type',
        'engine_volume', 'vin', 'plate_number', 'color', 'has_accident', 'flood_history', 'owners_count',
        'insurance_count', 'lien_status', 'seizure_status', 'options', 'photo_codes', 'is_active',
    ];

    private readonly ConnectionInterface $collector;

    private readonly ConnectionInterface $core;

    public function __construct(?ConnectionInterface $core = null, ?ConnectionInterface $collector = null)
    {
        $this->core = $core ?? DB::connection();
        $this->collector = $collector ?? DB::connection('collector');
    }

    /**
     * @return array{run_id: int, records: int, compared: int, only_collector: int, only_parser_active: int,
     *               fields: array<string, array{diff: int, rate: float, sample: list<array{lot_id: string, parser: mixed, collector: mixed}>}>}
     */
    public function compare(int $runId, string $source = 'encar', int $samples = 3): array
    {
        $fields = array_fill_keys(self::FIELDS, ['diff' => 0, 'rate' => 0.0, 'sample' => []]);
        $seen = [];
        $records = $compared = $onlyCollector = 0;

        $this->collector->table('ingest_lots')->where('run_id', $runId)->where('source', $source)
            ->orderBy('id')->chunkById(500, function ($rows) use (&$fields, &$seen, &$records, &$compared, &$onlyCollector, $samples) {
                $batch = [];
                foreach ($rows as $row) {
                    $records++;
                    $lot = json_decode((string) $row->payload, true);
                    if (! is_array($lot)) {
                        continue;
                    }
                    try {
                        $mapped = LotRowMapper::lotRow($lot, fn (array $codes) => null);
                    } catch (UnsupportedRecord) {
                        continue;
                    }
                    $passed = (bool) ($lot['selection']['passed'] ?? true);
                    $skipNew = ! $passed && ($lot['selection']['action'] ?? null) === 'skip';
                    $mapped['is_active'] = $passed ? 1 : 0;
                    $batch[$mapped['id']] = [$mapped, $skipNew];
                }
                $stored = $this->core->table('lots')->whereIn('id', array_keys($batch))
                    ->get(['id', ...self::FIELDS])->keyBy('id');
                foreach ($batch as $id => [$mapped, $skipNew]) {
                    $seen[$id] = true;
                    $old = $stored->get($id);
                    if ($old === null) {
                        $onlyCollector += $skipNew ? 0 : 1; // a skipped new lot is absent on both sides
                        continue;
                    }
                    $compared++;
                    foreach (self::FIELDS as $field) {
                        if ($field !== 'is_active' && $mapped[$field] === null) {
                            continue; // null = "keep what is stored" for the ingest too
                        }
                        $a = self::norm($field, $old->{$field});
                        $b = self::norm($field, $mapped[$field]);
                        if ($a !== $b) {
                            $fields[$field]['diff']++;
                            if (count($fields[$field]['sample']) < $samples) {
                                $fields[$field]['sample'][] = ['lot_id' => (string) $id, 'parser' => $a, 'collector' => $b];
                            }
                        }
                    }
                }
            });

        foreach ($fields as $name => $f) {
            $fields[$name]['rate'] = $compared ? round($f['diff'] / $compared * 100, 2) : 0.0;
        }

        $activeParser = $this->core->table('lots')->where('source', $source)->where('is_active', 1)->count();
        $seenActive = 0;
        foreach (array_chunk(array_keys($seen), 1000) as $chunk) {
            $seenActive += $this->core->table('lots')->whereIn('id', $chunk)->where('is_active', 1)->count();
        }

        return [
            'run_id' => $runId,
            'records' => $records,
            'compared' => $compared,
            'only_collector' => $onlyCollector,
            'only_parser_active' => $activeParser - $seenActive,
            'fields' => $fields,
        ];
    }

    private static function norm(string $field, mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }
        if ($field === 'options') {
            $list = is_string($v) ? json_decode($v, true) : $v;

            return is_array($list) ? array_values(array_map('strval', $list)) : $v;
        }
        if (is_bool($v)) {
            return (int) $v;
        }
        if ($field === 'engine_volume') {
            return round((float) $v, 1);
        }
        if (is_string($v) && is_numeric($v) && ! in_array($field, ['vin', 'plate_number', 'photo_codes', 'trim', 'model', 'make'], true)) {
            return $v + 0;
        }

        return $v;
    }
}
