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
        $samples = [];

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
            &$counts, &$samples
        ) {
            foreach ($rows as $row) {
                if ($limit > 0 && $counts['processed'] >= $limit) {
                    return false;
                }
                $counts['processed']++;

                $rawData  = $this->decodeRawData($row->raw_data ?? null);
                $badgeKr  = is_array($rawData) ? (string) ($rawData['badge_kr'] ?? '') : '';
                $modelRaw = (string) $row->model;
                $makeEn   = (string) ($row->make ?? '');
                $fullStr  = trim($modelRaw . ' ' . $badgeKr);

                $patch = [];

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
                    $fuelMap  = $tokenMaps['fuel']  ?? [];
                    $driveMap = $tokenMaps['drive'] ?? [];
                    $bodyMap  = $tokenMaps['body']  ?? [];

                    foreach (preg_split('/\s+/u', mb_strtolower($fullStr)) ?: [] as $tok) {
                        if (($row->fuel ?? '') === '' && !isset($patch['fuel']) && isset($fuelMap[$tok])) {
                            $patch['fuel'] = $fuelMap[$tok];
                        }
                        if (($row->drive_type ?? '') === '' && !isset($patch['drive_type']) && isset($driveMap[$tok])) {
                            $patch['drive_type'] = $driveMap[$tok];
                        }
                        if (($row->body_type ?? '') === '' && !isset($patch['body_type']) && isset($bodyMap[$tok])) {
                            $patch['body_type'] = $bodyMap[$tok];
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

                foreach (['model', 'generation', 'trim', 'fuel', 'drive_type', 'body_type', 'variant', 'package'] as $f) {
                    if (array_key_exists($f, $rulePatch) && $rulePatch[$f] !== null) {
                        $patch[$f] = $rulePatch[$f];
                    }
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

                $samples[] = [
                    'id'      => $row->id,
                    'model'   => $row->model . ' → ' . ($patch['model'] ?? $row->model),
                    'trim'    => ($row->trim ?? '') . ' → ' . ($patch['trim'] ?? $row->trim ?? ''),
                    'gen'     => ($row->generation ?? '') . ' → ' . ($patch['generation'] ?? $row->generation ?? ''),
                    'catalog' => $catalogResult !== null ? 'HIT' : 'MISS',
                ];

                if ($apply) {
                    $patch['updated_at'] = now();
                    DB::table('lots')->where('id', $row->id)->update($patch);
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
        }

        if (!empty($samples)) {
            $this->line('');
            $this->line('Samples:');
            foreach ($samples as $s) {
                $this->line(sprintf('#%s [%s] %s | trim: %s | gen: %s',
                    $s['id'], $s['catalog'], $s['model'], $s['trim'], $s['gen']));
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
