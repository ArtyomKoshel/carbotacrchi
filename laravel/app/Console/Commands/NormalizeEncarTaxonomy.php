<?php

namespace App\Console\Commands;

use App\Models\TaxonomyAnomalyQueue;
use App\Services\Catalog\CatalogLookupService;
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
        {--only-empty : Only process lots where trim IS NULL or empty}';

    protected $description = 'Normalize Encar lot fields using catalog lookup + rule engine';

    private CatalogLookupService $catalog;

    public function handle(
        TaxonomyRuleEngine       $ruleEngine,
        TaxonomySuggestionService $suggestions,
        CatalogLookupService     $catalog,
    ): int {
        $this->catalog = $catalog;

        $apply  = (bool) $this->option('apply');
        $limit  = max(0, (int) $this->option('limit'));
        $chunk  = max(100, (int) $this->option('chunk'));
        $source = (string) $this->option('source');
        $random = (bool) $this->option('random');

        $this->info('Normalize Encar Taxonomy');
        $this->line('Mode: ' . ($apply ? 'APPLY' : 'DRY-RUN') . ($random ? ' [RANDOM]' : ''));
        $this->line("Source: {$source}  Chunk: {$chunk}  Limit: " . ($limit ?: 'all'));

        // Load token maps once for the whole run
        $tokenMaps = $this->catalog->tokenMaps();

        // Build model-prefix list sorted by length desc (longer phrases match before single words)
        $modelPrefixes = array_keys($tokenMaps['model_prefix'] ?? []);
        usort($modelPrefixes, static fn($a, $b) => mb_strlen($b) - mb_strlen($a));

        $counts = array_fill_keys(
            ['processed', 'wouldUpdate', 'updated', 'catalogHits', 'anomalies',
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
            $apply, $limit, $source, $ruleEngine, $suggestions, $tokenMaps, $modelPrefixes,
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
                // Captures the exact column values as they existed before this command
                // ran for the first time, so we can always audit what the parser produced.
                $rawDataDirty = false;
                if (!isset($rawData['pre_norm'])) {
                    $snap = [];
                    foreach (['model','generation','trim','fuel','drive_type',
                              'body_type','engine_volume','seat_count','variant'] as $_f) {
                        $v = $row->$_f ?? null;
                        if ($v !== null && $v !== '') {
                            $snap[$_f] = $v;
                        }
                    }
                    $rawData['pre_norm'] = $snap;
                    $rawDataDirty = true;
                }

                // ── Layer 1: Catalog lookup ──────────────────────────────
                $catalogResult = $this->catalog->lookup($makeEn, $modelRaw);

                if ($catalogResult !== null) {
                    $counts['catalogHits']++;

                    $cleanModel = $this->stripModelPrefix($catalogResult->modelKr, $modelPrefixes);
                    if ($cleanModel !== $modelRaw && $cleanModel !== '') {
                        $patch['model'] = $cleanModel;
                    }
                    if (($row->generation ?? '') === '' && $catalogResult->generation !== null) {
                        $patch['generation'] = $catalogResult->generation;
                    }
                    $trimCurrent = trim((string) ($row->trim ?? ''));
                    $trimIsNull  = $trimCurrent === '' || $trimCurrent === '(세부등급 없음)';
                    if ($trimIsNull && $catalogResult->trimKr !== null && $catalogResult->trimKr !== '') {
                        $patch['trim'] = $catalogResult->trimKr;
                    }
                    if (($row->fuel ?? '') === '' && $catalogResult->fuelType !== null) {
                        $patch['fuel'] = $catalogResult->fuelType;
                    }
                    if (($row->drive_type ?? '') === '' && $catalogResult->driveType !== null) {
                        $patch['drive_type'] = $catalogResult->driveType;
                    }
                    if ($row->engine_volume === null && $catalogResult->engineVolume !== null) {
                        $patch['engine_volume'] = $catalogResult->engineVolume;
                    }
                    if (($row->seat_count ?? null) === null && $catalogResult->seatCount !== null) {
                        $patch['seat_count'] = $catalogResult->seatCount;
                    }
                    if (($row->cylinders ?? null) === null && $catalogResult->cylinders !== null) {
                        $patch['cylinders'] = $catalogResult->cylinders;
                    }
                    if (($row->body_type ?? '') === '' && $catalogResult->bodyHint !== null) {
                        $patch['body_type'] = $catalogResult->bodyHint;
                    }
                } else {
                    // ── Layer 2: Token map fallback (for catalog misses) ──
                    // Scan every token in fullStr, route matched tokens to columns,
                    // keep unmatched tokens as trim candidates.
                    $fuelMap      = $tokenMaps['fuel']          ?? [];
                    $driveMap     = $tokenMaps['drive']         ?? [];
                    $bodyMap      = $tokenMaps['body']          ?? [];
                    $specCodeMap  = $tokenMaps['grade_spec_code']  ?? [];
                    $engineVolMap = $tokenMaps['grade_engine_vol'] ?? [];
                    $engFamMap    = $tokenMaps['engine_family']    ?? [];
                    $cylMap       = $tokenMaps['cylinder_config']  ?? [];
                    $genLabelMap  = $tokenMaps['grade_gen_label']  ?? [];
                    $seatMap      = $tokenMaps['grade_seat']       ?? [];
                    $trimNameMap  = $tokenMaps['grade_trim_name']  ?? [];

                    $trimTokens = [];
                    $badgeStr   = trim($badgeKr);

                    foreach (preg_split('/\s+/u', $badgeStr !== '' ? $badgeStr : $fullStr) ?: [] as $rawTok) {
                        $tok = mb_strtolower($rawTok);

                        if (($row->fuel ?? '') === '' && !isset($patch['fuel']) && isset($fuelMap[$tok])) {
                            $patch['fuel'] = $fuelMap[$tok]; continue;
                        }
                        if (($row->drive_type ?? '') === '' && !isset($patch['drive_type']) && isset($driveMap[$tok])) {
                            $patch['drive_type'] = $driveMap[$tok]; continue;
                        }
                        if (($row->body_type ?? '') === '' && !isset($patch['body_type']) && isset($bodyMap[$tok])) {
                            $patch['body_type'] = $bodyMap[$tok]; continue;
                        }
                        // Spec / engine tokens: strip but don't store (catalog miss = no columns)
                        if (isset($specCodeMap[$tok]) || isset($engineVolMap[$tok])
                            || isset($engFamMap[$tok]) || isset($cylMap[$tok])
                            || isset($genLabelMap[$tok]) || isset($seatMap[$tok])) {
                            continue;
                        }
                        // Engine volume numeric pattern (e.g. "2.0", "3.5")
                        if (preg_match('/^(\d+\.\d+)$/u', $rawTok, $m)) {
                            $v = (float) $m[1];
                            if ($v >= 0.5 && $v <= 10.0) {
                                if ($row->engine_volume === null && !isset($patch['engine_volume'])) {
                                    $patch['engine_volume'] = $v;
                                }
                                continue;
                            }
                        }
                        // Seat count pattern (e.g. "7인승")
                        if (preg_match('/^(\d{1,2})인승$/u', $rawTok, $m)) {
                            if (($row->seat_count ?? null) === null && !isset($patch['seat_count'])) {
                                $patch['seat_count'] = (int) $m[1];
                            }
                            continue;
                        }
                        // Everything else is a trim candidate
                        $trimTokens[] = $rawTok;
                    }

                    // Compose trim from remaining tokens
                    $trimCurrent = trim((string) ($row->trim ?? ''));
                    $trimIsNull  = $trimCurrent === '' || $trimCurrent === '(세부등급 없음)';
                    if ($trimIsNull && $trimTokens !== []) {
                        $candidate = trim(implode(' ', $trimTokens));
                        if ($candidate !== '') {
                            $patch['trim'] = $candidate;
                        }
                    }

                    // Queue for AI + catalog enrichment
                    $this->upsertAnomalyQueue($source, $makeEn, (string) $row->id, $modelRaw, $suggestions);
                    $counts['anomalies']++;
                }

                // ── Layer 3: Rule engine (overrides, edge cases) ─────────
                $rulePatch = $ruleEngine->apply([
                    'source'      => $source,
                    'lot_id'      => (string) $row->id,
                    'make'        => $makeEn,
                    'model_raw'   => $modelRaw,
                    'badge_raw'   => $badgeKr,
                    'model'       => $patch['model'] ?? $modelRaw,
                    'generation'  => $patch['generation'] ?? ($row->generation ?? null),
                    'trim'        => $patch['trim'] ?? ($row->trim ?? null),
                    'unknown_tail' => null,
                ], false);

                // Merge rule engine output.
                // model is special: the engine always echoes it back unchanged when no rule
                // fires — only accept the value when it actually differs from what went in.
                $preRuleModel = $patch['model'] ?? $modelRaw;
                foreach (['model', 'generation', 'trim', 'fuel', 'drive_type', 'body_type', 'variant', 'package'] as $f) {
                    if (!array_key_exists($f, $rulePatch) || $rulePatch[$f] === null) {
                        continue;
                    }
                    if ($f === 'model' && $rulePatch['model'] === $preRuleModel) {
                        continue; // engine echoed model unchanged — don't pollute the patch
                    }
                    $patch[$f] = $rulePatch[$f];
                }

                // Strip any field whose new value already matches the current DB row.
                // Prevents inflated counts and spurious updated_at touches.
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

                // Flush raw_data if pre_norm was just written
                if ($rawDataDirty) {
                    $patch['raw_data'] = json_encode($rawData, JSON_UNESCAPED_UNICODE);
                }

                if ($patch === []) {
                    continue;
                }

                $counts['wouldUpdate']++;
                foreach (['model', 'generation', 'trim', 'fuel', 'drive_type', 'body_type', 'engine_volume', 'seat_count', 'cylinders'] as $f) {
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
                    // raw_data is JSON — exclude from the scalar-comparison dedup above
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
            $processRow($baseQuery->inRandomOrder()->limit($limit ?: PHP_INT_MAX)->get());
        } else {
            $baseQuery->orderBy('id')->chunkById($chunk, function ($rows) use ($processRow, &$counts, $total, $startTime, $limit) {
                $result = $processRow($rows);
                $pct     = $total > 0 ? round($counts['processed'] / $total * 100) : 0;
                $elapsed = round(microtime(true) - $startTime);
                $this->line("  [{$pct}%] {$counts['processed']}/{$total} | {$elapsed}s | catalog_hits={$counts['catalogHits']} anomalies={$counts['anomalies']}");
                if ($limit > 0 && $counts['processed'] >= $limit) {
                    return false;
                }
                return $result;
            }, 'id', 'id');
        }

        $this->line('');
        $this->line("Processed:    {$counts['processed']}");
        $this->line("Would update: {$counts['wouldUpdate']}");
        $this->line("Catalog hits: {$counts['catalogHits']}");
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
        }

        return self::SUCCESS;
    }

    private function upsertAnomalyQueue(
        string $source, string $make, string $lotId,
        string $modelRaw, TaxonomySuggestionService $suggestions
    ): void {
        $source = trim($source) ?: 'encar';
        $make   = trim($make) ?: null;

        $reason = 'catalog_miss';
        $row = TaxonomyAnomalyQueue::query()
            ->where('source', $source)
            ->where('make', $make)
            ->where('sample_model_raw', $modelRaw)
            ->where('reason', $reason)
            ->first();

        if ($row) {
            $row->seen_count  = (int) $row->seen_count + 1;
            $row->last_seen_at = now();
            $row->save();
            return;
        }

        $s = $suggestions->suggest('', $modelRaw);
        TaxonomyAnomalyQueue::query()->create([
            'source'              => $source,
            'make'                => $make,
            'unknown_tail'        => $modelRaw,
            'reason'              => $reason,
            'sample_lot_id'       => $lotId,
            'sample_model_raw'    => $modelRaw,
            'seen_count'          => 1,
            'first_seen_at'       => now(),
            'last_seen_at'        => now(),
            'status'              => 'new',
            'suggested_action'    => $s['action'],
            'suggested_value'     => $s['value'],
            'suggestion_confidence' => $s['confidence'],
        ]);
    }

    /**
     * Strip a known marketing prefix from the beginning of a model name.
     * Prefixes come from catalog_token_maps (type = model_prefix), sorted by length desc.
     *
     * Examples: "더 뉴 아반떼" → "아반떼", "올뉴 K5" → "K5", "뉴 SM6" → "SM6"
     *
     * @param string[] $prefixes  Sorted by mb_strlen desc (longer first)
     */
    private function stripModelPrefix(string $model, array $prefixes): string
    {
        $model = trim($model);
        foreach ($prefixes as $prefix) {
            $candidate = $prefix . ' ';
            if (mb_strpos($model, $candidate) === 0) {
                $stripped = trim(mb_substr($model, mb_strlen($candidate)));
                if ($stripped !== '') {
                    return $stripped;
                }
            }
        }
        return $model;
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
