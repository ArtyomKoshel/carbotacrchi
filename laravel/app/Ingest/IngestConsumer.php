<?php

namespace App\Ingest;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Reads CarLot records from the collector's buffer (collector.ingest_lots)
 * and applies them to `lots`, `lot_inspections`, `lot_changes` and
 * `filter_skip_log` (docs/adr/0003, 0004). The core is the only writer of
 * those tables; this is the only way collector data reaches them.
 *
 * Idempotent: the read offset moves in the same transaction as the writes, and
 * a record whose content hash and activity match the stored lot only marks the
 * lot as seen (parsed_at), so a replayed batch changes nothing else.
 */
final class IngestConsumer
{
    public const CONSUMER = 'core';

    private readonly ConnectionInterface $core;

    private readonly ConnectionInterface $collector;

    public function __construct(
        private readonly CarLotContract $contract,
        ?ConnectionInterface $core = null,
        ?ConnectionInterface $collector = null,
    ) {
        $this->core = $core ?? DB::connection();
        $this->collector = $collector ?? DB::connection('collector');
    }

    /** Applies one batch; returns the number of buffer rows consumed (0 = nothing new). */
    public function consumeBatch(int $limit = 500, ?string $now = null): IngestResult
    {
        $now ??= gmdate('Y-m-d H:i:s');

        return $this->core->transaction(function () use ($limit, $now) {
            $offset = $this->lockOffset();
            $records = $this->collector->table('ingest_lots')
                ->where('id', '>', $offset)->orderBy('id')->limit($limit)
                ->get(['id', 'source', 'source_id', 'content_hash', 'payload']);
            if ($records->isEmpty()) {
                return new IngestResult;
            }

            $result = new IngestResult(consumed: $records->count());
            $lots = $this->decode($records, $result);
            if ($lots !== []) {
                $this->apply($lots, $now, $result);
            }
            $this->core->table('ingest_offsets')->where('consumer', self::CONSUMER)
                ->update(['last_id' => $records->last()->id, 'updated_at' => $now]);

            return $result;
        });
    }

    /** Runs batches until the buffer is drained or $maxBatches is reached (0 = no limit). */
    public function drain(int $batchSize = 500, int $maxBatches = 0): IngestResult
    {
        $total = new IngestResult;
        for ($i = 0; $maxBatches === 0 || $i < $maxBatches; $i++) {
            $batch = $this->consumeBatch($batchSize);
            if ($batch->consumed === 0) {
                break;
            }
            $total = $total->plus($batch);
        }

        return $total;
    }

    private function lockOffset(): int
    {
        $this->core->table('ingest_offsets')->insertOrIgnore(['consumer' => self::CONSUMER, 'last_id' => 0]);

        return (int) $this->core->table('ingest_offsets')
            ->where('consumer', self::CONSUMER)->lockForUpdate()->value('last_id');
    }

    /**
     * Validates every record; refused ones go to ingest_rejections. A lot that
     * appears twice in a batch keeps its last record.
     *
     * @param  iterable<object>  $records
     * @return array<string, array{record: object, lot: array<string, mixed>}>
     */
    private function decode(iterable $records, IngestResult $result): array
    {
        $lots = [];
        foreach ($records as $record) {
            try {
                $errors = $this->contract->errors((string) $record->payload);
                $lot = $errors === [] ? json_decode((string) $record->payload, true, 512, JSON_THROW_ON_ERROR) : null;
            } catch (JsonException $e) {
                $errors = ['/: invalid JSON: '.$e->getMessage()];
                $lot = null;
            }
            if ($lot === null) {
                $this->reject($record, $errors, $result);

                continue;
            }
            $lots[LotRowMapper::lotId($lot['source'], $lot['source_id'])] = ['record' => $record, 'lot' => $lot];
        }

        return $lots;
    }

    /** @param array<string, array{record: object, lot: array<string, mixed>}> $lots */
    private function apply(array $lots, string $now, IngestResult $result): void
    {
        $stored = $this->core->table('lots')->whereIn('id', array_keys($lots))
            ->get(['id', 'ingest_hash', ...LotChangeDetector::TRACKED])->keyBy('id');
        $rules = $this->rules($lots);

        $upserts = $inspections = $changes = $skipLog = $seen = $deactivate = [];
        foreach ($lots as $id => ['record' => $record, 'lot' => $lot]) {
            $old = $stored->get($id);
            $selection = $lot['selection'] ?? ['passed' => true];
            $passed = (bool) $selection['passed'];

            try {
                $row = LotRowMapper::lotRow($lot);
            } catch (UnsupportedRecord $e) {
                $this->reject($record, [$e->getMessage()], $result);

                continue;
            }
            if (! $passed) {
                foreach ($selection['rule_ids'] as $ruleId) {
                    $skipLog[] = $this->skipLogRow($lot, $row, $selection['action'], $rules[$ruleId] ?? null, $ruleId);
                }
            }

            // skip: a new lot is not stored; a stored one only goes inactive.
            if (! $passed && $selection['action'] === 'skip') {
                $result->skipped++;
                if ($old !== null && (int) $old->is_active === 1) {
                    $deactivate[] = $id;
                    $changes[] = $this->change($id, $lot['source'], 'deactivated_filter', ['is_active' => ['old' => 1, 'new' => 0]], $now);
                }

                continue;
            }

            $row['is_active'] = $passed ? 1 : 0;
            $row['history_checked_at'] = LotRowMapper::historyCheckedAt($lot);
            $row['ingest_hash'] = $record->content_hash;

            if ($old !== null && $old->ingest_hash === $record->content_hash && (int) $old->is_active === $row['is_active']) {
                $seen[] = $id; // same content, same state: only "seen in this run"
                $result->unchanged++;

                continue;
            }

            $change = LotChangeDetector::detect($old === null ? null : (array) $old, $row);
            if ($change !== null) {
                $changes[] = $this->change($id, $lot['source'], $change['event'], $change['changes'], $now);
            }
            $upserts[] = [...$row, 'parsed_at' => $now, 'updated_at' => $now, 'created_at' => $now];
            $old === null ? $result->inserted++ : $result->updated++;

            if (($inspection = LotRowMapper::inspectionRow($lot)) !== null) {
                $inspections[] = [...$inspection, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        $upsert = new LotUpsert($this->core);
        foreach (array_chunk($upserts, 200) as $chunk) {
            $upsert->lots($chunk);
        }
        foreach (array_chunk($inspections, 200) as $chunk) {
            $upsert->inspections($chunk);
        }
        foreach (array_chunk($seen, 1000) as $chunk) {
            $this->core->table('lots')->whereIn('id', $chunk)->update(['parsed_at' => $now]);
        }
        foreach (array_chunk($deactivate, 1000) as $chunk) {
            $this->core->table('lots')->whereIn('id', $chunk)->update(['is_active' => 0, 'updated_at' => $now]);
        }
        foreach (array_chunk($changes, 500) as $chunk) {
            $this->core->table('lot_changes')->insert($chunk);
        }
        $this->writeSkipLog($skipLog);
        $result->changes += count($changes);
    }

    /**
     * @param  array<string, array{record: object, lot: array<string, mixed>}>  $lots
     * @return array<int, object>
     */
    private function rules(array $lots): array
    {
        $ids = [];
        foreach ($lots as ['lot' => $lot]) {
            foreach ($lot['selection']['rule_ids'] ?? [] as $id) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        return $this->core->table('parse_filters')->whereIn('id', array_keys($ids))
            ->get(['id', 'name', 'field'])->keyBy('id')->all();
    }

    /**
     * @param  array<string, mixed>  $lot
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function skipLogRow(array $lot, array $row, string $action, ?object $rule, int $ruleId): array
    {
        $field = $rule->field ?? null;
        $value = $field !== null && array_key_exists($field, $row) ? $row[$field] : null;

        return [
            'source' => $lot['source'],
            'source_id' => $lot['source_id'],
            'lot_url' => $lot['url'],
            'rule_name' => $rule->name ?? "rule_{$ruleId}",
            'rule_id' => $ruleId,
            'action' => $action,
            'field_name' => $field,
            'field_value' => $value === null ? null : (is_scalar($value) ? (string) $value : json_encode($value)),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function writeSkipLog(array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $columns = array_keys($chunk[0]);
            $placeholders = implode(', ', array_fill(0, count($chunk), '('.implode(', ', array_fill(0, count($columns), '?')).')'));
            $bindings = array_merge(...array_map(fn (array $r) => array_values($r), $chunk));
            $this->core->statement(
                'INSERT INTO filter_skip_log (`'.implode('`, `', $columns)."`) VALUES {$placeholders} AS new
                 ON DUPLICATE KEY UPDATE lot_url = new.lot_url, rule_id = new.rule_id, action = new.action,
                    field_name = new.field_name, field_value = new.field_value,
                    last_skipped_at = CURRENT_TIMESTAMP, skip_count = filter_skip_log.skip_count + 1",
                $bindings,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $diff
     * @return array<string, mixed>
     */
    private function change(string $lotId, string $source, string $event, array $diff, string $now): array
    {
        return [
            'lot_id' => $lotId,
            'source' => $source,
            'event' => $event,
            'changes' => json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'recorded_at' => $now,
        ];
    }

    /** @param list<string> $errors */
    private function reject(object $record, array $errors, IngestResult $result): void
    {
        $this->core->table('ingest_rejections')->insert([
            'ingest_id' => $record->id,
            'source' => $record->source,
            'source_id' => $record->source_id,
            'errors' => json_encode(array_values($errors), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $result->rejected++;
    }
}
