<?php

namespace App\Console\Commands;

use App\Models\TaxonomyAnomalyQueue;
use App\Services\Catalog\GradeExtractorService;
use App\Services\Taxonomy\TaxonomyRuleEngine;
use App\Services\Taxonomy\TaxonomySuggestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeEncarTaxonomy extends Command
{
    protected $signature = 'lots:normalize-encar-taxonomy
        {--apply : Persist updates (default mode is dry-run)}
        {--limit=0 : Max lots to scan (0 = no limit)}
        {--chunk=2000 : Chunk size for scanning rows}
        {--source=encar : Source to process}
        {--random : Sample lots in random order}
        {--only-empty : Only process lots where trim IS NULL or empty}
        {--dump-trims= : Write all proposed trim values (sorted by freq) to this file path}';

    protected $description = 'Normalize Encar lot fields using GradeExtractorService + rule engine';

    public function handle(
        TaxonomyRuleEngine        $ruleEngine,
        TaxonomySuggestionService $suggestions,
        GradeExtractorService     $extractor,
    ): int {
        $apply  = (bool) $this->option('apply');
        $limit  = max(0, (int) $this->option('limit'));
        $chunk  = max(100, (int) $this->option('chunk'));
        $source = (string) $this->option('source');
        $random = (bool) $this->option('random');

        $this->info('Normalize Encar Taxonomy');
        $this->line('Mode: ' . ($apply ? 'APPLY' : 'DRY-RUN') . ($random ? ' [RANDOM]' : ''));
        $this->line("Source: {$source}  Chunk: {$chunk}  Limit: " . ($limit ?: 'all'));

        $counts = array_fill_keys(
            ['processed', 'wouldUpdate', 'updated', 'anomalies',
             'model', 'generation', 'trim', 'fuel', 'drive_type', 'body_type',
             'engine_volume', 'seat_count', 'cylinders'],
            0
        );
        $trimSamples = [];

        $baseQuery = DB::table('lots')
            ->where('source', $source)
            ->whereNotNull('model')
            ->where('model', '!=', '');

        if ($this->option('only-empty')) {
            $baseQuery->where(function ($q) {
                $q->whereNull('trim')->orWhere('trim', '')->orWhere('trim', '(세부등급 없음)');
            });
            $this->line('Filter: only-empty');
        }

        $total     = (clone $baseQuery)->count();
        $startTime = microtime(true);
        if ($limit > 0 && $limit < $total) {
            $total = $limit;
        }
        $this->line("Total: {$total}");

        $processRow = function ($rows) use (
            $apply, $limit, $source, $ruleEngine, $suggestions, $extractor,
            &$counts, &$trimSamples
        ) {
            foreach ($rows as $row) {
                if ($limit > 0 && $counts['processed'] >= $limit) {
                    return false;
                }
                $counts['processed']++;

                $rawData  = $this->decodeRawData($row->raw_data ?? null) ?? [];
                $badgeKr  = (string) ($rawData['badge_kr'] ?? '');
                $modelRaw = (string) $row->model;
                $makeEn   = (string) ($row->make ?? '');
                $fullStr  = trim($modelRaw . ' ' . $badgeKr);

                $patch = [];

                // ── Preserve pre-normalisation snapshot (written ONCE, never overwritten) ──
                $rawDataDirty = false;
                if (!isset($rawData['pre_norm'])) {
                    $snap = [];
                    foreach (['model', 'generation', 'trim', 'fuel', 'drive_type',
                              'body_type', 'engine_volume', 'seat_count', 'variant'] as $_f) {
                        $v = $row->$_f ?? null;
                        if ($v !== null && $v !== '') {
                            $snap[$_f] = $v;
                        }
                    }
                    $rawData['pre_norm'] = $snap;
                    $rawDataDirty = true;
                }

                // ── Extraction (positive matching across all catalogs) ────
                $extracted = $extractor->extract($fullStr, $makeEn);

                // Map extracted fields to patch — only fill currently empty DB columns
                if ($extracted->model !== null && $extracted->model !== $modelRaw) {
                    $patch['model'] = $extracted->model;
                }
                if ($extracted->generation !== null && ($row->generation ?? '') === '') {
                    $patch['generation'] = $extracted->generation;
                }
                $trimCurrent = trim((string) ($row->trim ?? ''));
                if (($trimCurrent === '' || $trimCurrent === '(세부등급 없음)')
                    && $extracted->trim !== null && $extracted->trim !== '') {
                    $patch['trim'] = $extracted->trim;
                }
                if (($row->fuel ?? '') === '' && $extracted->fuel !== null) {
                    $patch['fuel'] = $extracted->fuel;
                }
                if (($row->drive_type ?? '') === '' && $extracted->driveType !== null) {
                    $patch['drive_type'] = $extracted->driveType;
                }
                if (($row->body_type ?? '') === '' && $extracted->bodyType !== null) {
                    $patch['body_type'] = $extracted->bodyType;
                }
                if ($row->engine_volume === null && $extracted->engineVolume !== null) {
                    $patch['engine_volume'] = $extracted->engineVolume;
                }
                if (($row->seat_count ?? null) === null && $extracted->seatCount !== null) {
                    $patch['seat_count'] = $extracted->seatCount;
                }
                if (($row->cylinders ?? null) === null && $extracted->cylinders !== null) {
                    $patch['cylinders'] = $extracted->cylinders;
                }

                // Route unrecognized remainder to anomaly queue
                if ($extracted->remainder !== '') {
                    $this->upsertAnomalyQueue($source, $makeEn, (string) $row->id, $fullStr, $extracted->remainder, $suggestions);
                    $counts['anomalies']++;
                }

                // ── Rule engine (overrides and edge-case corrections) ────
                $rulePatch = $ruleEngine->apply([
                    'source'       => $source,
                    'lot_id'       => (string) $row->id,
                    'make'         => $makeEn,
                    'model_raw'    => $modelRaw,
                    'badge_raw'    => $badgeKr,
                    'model'        => $patch['model'] ?? $modelRaw,
                    'generation'   => $patch['generation'] ?? ($row->generation ?? null),
                    'trim'         => $patch['trim'] ?? ($row->trim ?? null),
                    'unknown_tail' => $extracted->remainder ?: null,
                ], false);

                $preRuleModel = $patch['model'] ?? $modelRaw;
                foreach (['model', 'generation', 'trim', 'fuel', 'drive_type', 'body_type', 'variant', 'package'] as $f) {
                    if (!array_key_exists($f, $rulePatch) || $rulePatch[$f] === null) {
                        continue;
                    }
                    if ($f === 'model' && $rulePatch['model'] === $preRuleModel) {
                        continue;
                    }
                    $patch[$f] = $rulePatch[$f];
                }

                // Strip fields whose new value already matches the current DB row
                foreach (array_keys($patch) as $f) {
                    $dbVal  = $row->$f ?? null;
                    $newVal = $patch[$f];
                    if (is_float($newVal)) {
                        $dbVal = $dbVal !== null ? (float) $dbVal : null;
                    } elseif (is_int($newVal)) {
                        $dbVal = $dbVal !== null ? (int) $dbVal : null;
                    } else {
                        $dbVal  = (string) ($dbVal ?? '');
                        $newVal = (string) $newVal;
                    }
                    if ($newVal === $dbVal) {
                        unset($patch[$f]);
                    }
                }

                if ($rawDataDirty) {
                    $patch['raw_data'] = json_encode($rawData, JSON_UNESCAPED_UNICODE);
                }

                if ($patch === []) {
                    continue;
                }

                $counts['wouldUpdate']++;
                foreach (['model', 'generation', 'trim', 'fuel', 'drive_type', 'body_type',
                          'engine_volume', 'seat_count', 'cylinders'] as $f) {
                    if (isset($patch[$f])) {
                        $counts[$f]++;
                    }
                }
                if (!$apply && isset($patch['trim'])) {
                    $v = (string) $patch['trim'];
                    $trimSamples[$v] = ($trimSamples[$v] ?? 0) + 1;
                }

                if ($apply) {
                    $patch['updated_at'] = now();
                    DB::table('lots')->where('id', $row->id)->update(
                        array_diff_key($patch, ['raw_data' => true])
                        + (isset($patch['raw_data']) ? ['raw_data' => $patch['raw_data']] : [])
                    );
                    $counts['updated']++;
                }
            }
            return true;
        };

        if ($random) {
            $randomLimit = $limit ?: 10000;  // safety cap: never load entire table into memory
            $processRow($baseQuery->inRandomOrder()->limit($randomLimit)->get());
        } else {
            $baseQuery->orderBy('id')->chunkById($chunk, function ($rows) use ($processRow, &$counts, $total, $startTime, $limit) {
                $processRow($rows);
                $pct     = $total > 0 ? round($counts['processed'] / $total * 100) : 0;
                $elapsed = round(microtime(true) - $startTime);
                $this->line("  [{$pct}%] {$counts['processed']}/{$total} | {$elapsed}s | anomalies={$counts['anomalies']}");
                if ($limit > 0 && $counts['processed'] >= $limit) {
                    return false;
                }
                return true;
            }, 'id', 'id');
        }

        $this->line('');
        $this->line("Processed:    {$counts['processed']}");
        $this->line("Would update: {$counts['wouldUpdate']}");
        $this->line("Anomalies:    {$counts['anomalies']}");
        $this->line("  model={$counts['model']}  gen={$counts['generation']}  trim={$counts['trim']}");
        $this->line("  fuel={$counts['fuel']}  drive={$counts['drive_type']}  body={$counts['body_type']}");
        $this->line("  engine_vol={$counts['engine_volume']}  seats={$counts['seat_count']}  cyl={$counts['cylinders']}");
        if ($apply) {
            $this->line("Updated:      {$counts['updated']}");
        } else {
            arsort($trimSamples);
            $top = array_slice($trimSamples, 0, 30, true);
            if (!empty($top)) {
                $this->line('');
                $this->line('Top proposed trim values (dry-run):');
                foreach ($top as $val => $cnt) {
                    $this->line(sprintf('  %4d × %s', $cnt, $val));
                }
            }
            $dumpPath = $this->option('dump-trims');
            if ($dumpPath && !empty($trimSamples)) {
                $lines = [];
                foreach ($trimSamples as $val => $cnt) {
                    $lines[] = sprintf('%d\t%s', $cnt, $val);
                }
                file_put_contents($dumpPath, implode("\n", $lines) . "\n");
                $this->line('');
                $this->line("Trim dump written to: {$dumpPath} (" . count($trimSamples) . ' unique values)');
            }
        }

        return self::SUCCESS;
    }

    private function upsertAnomalyQueue(
        string $source, string $make, string $lotId,
        string $fullStr, string $remainder, TaxonomySuggestionService $suggestions
    ): void {
        $source = trim($source) ?: 'encar';
        $make   = trim($make) ?: null;

        $reason = 'unknown_remainder';
        $row = TaxonomyAnomalyQueue::query()
            ->where('source', $source)
            ->where('make', $make)
            ->where('unknown_tail', $remainder)
            ->where('reason', $reason)
            ->first();

        if ($row) {
            $row->seen_count   = (int) $row->seen_count + 1;
            $row->last_seen_at = now();
            $row->save();
            return;
        }

        $s = $suggestions->suggest('', $remainder);
        TaxonomyAnomalyQueue::query()->create([
            'source'                => $source,
            'make'                  => $make,
            'unknown_tail'          => $remainder,
            'reason'                => $reason,
            'sample_lot_id'         => $lotId,
            'sample_model_raw'      => $fullStr,
            'seen_count'            => 1,
            'first_seen_at'         => now(),
            'last_seen_at'          => now(),
            'status'                => 'new',
            'suggested_action'      => $s['action'],
            'suggested_value'       => $s['value'],
            'suggestion_confidence' => $s['confidence'],
        ]);
    }

    private function decodeRawData(mixed $rawData): ?array
    {
        if (is_array($rawData)) {
            return $rawData;
        }
        if (!is_string($rawData) || $rawData === '') {
            return null;
        }
        $decoded = json_decode($rawData, true);
        return is_array($decoded) ? $decoded : null;
    }
}
