<?php

namespace App\Console\Commands;

use App\Models\TaxonomyAnomalyQueue;
use App\Services\Taxonomy\TaxonomyRuleEngine;
use App\Services\Taxonomy\TaxonomySuggestionService;
use App\Services\Taxonomy\TaxonomyTermService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeEncarTaxonomy extends Command
{
    protected $signature = 'lots:normalize-encar-taxonomy
        {--apply : Persist updates (default mode is dry-run)}
        {--limit=0 : Max lots to scan (0 = no limit)}
        {--chunk=1000 : Chunk size for scanning rows}
        {--source=encar : Source to process (kept for flexibility)}
        {--random : Sample lots in random order (for quick dry-run iteration)}';

    protected $description = 'Normalize Encar model taxonomy: clean model, infer generation, and fill empty trim where possible';

    private const UNKNOWN_TAIL_HINT_RE = '/(에디션|라인|스페셜|패키지|플러스|스타일|셀렉션)$/u';
    private const MODEL_PREFIX_RE = '/^(?:더\s+뉴|더|올\s+뉴|올뉴|뉴|신형)\s+/u';

    private const DEFAULT_GEN_NON_CHASSIS = [
        'EV', 'HEV', 'PHEV', 'GDI', 'TDI', 'TFSI', 'MPI',
        'AWD', 'FWD', 'RWD', '4WD', '2WD',
        // Genesis model codes
        'G70', 'G80', 'G90', 'GV70', 'GV80', 'GV90', 'EQ900',
        // Volvo model codes
        'XC40', 'XC60', 'XC90', 'S60', 'S90', 'V60', 'V90', 'C40',
        // Volvo engine designations (diesel/petrol/mild-hybrid)
        'D3', 'D4', 'D5', 'T4', 'T5', 'T6', 'T8', 'B4', 'B5', 'B6',
    ];
    private const DEFAULT_GEN_EXCLUDE = [
        'V6', 'V8', 'V10', 'V12', 'Q4',
        'VS380', 'CW700', 'EL300', 'G330',
        // Genesis engine grade codes
        'G300', 'G350', 'G380', 'G400', 'G450',
        // Hyundai Grandeur engine+gen tokens
        'HG300', 'HG330',
    ];
    private const DEFAULT_ENGINE_FAMILY = [
        'GDI', 'T-GDI', 'TGDI', 'GDE', 'MPI', 'MPFI',
        'TFSI', 'TSI', 'FSI',
        'TDI', 'CRDI', 'VGT', 'TCI', 'E-VGT',
        'FHEV', 'HEV', 'LPI', 'LPE', 'EV', 'BEV',
        '4MATIC', '블루텍', 'GTE', 'LPLI', '4TRONIC',
    ];
    private const BODY_STYLE_TOKENS = [
        '쿠페', '카브리올레', '컨버터블', '왜건', '해치백', '픽업', '밴',
        'Coupe', 'Cabriolet', 'Convertible', 'Wagon',
        '5도어', '4도어', '3도어', '2도어',
    ];

    private const DEFAULT_VARIANT_EXCLUDE = [
        'EV', 'BEV', 'HEV', 'FHEV',
        'GDI', 'TDI', 'MPI', 'CRDI', 'VGT', 'TCI', 'TFSI', 'TSI', 'FSI',
        'GDE', 'GTE', 'LPLI', 'LPE', 'LPI',
        '4WD', '2WD', 'AWD', 'FWD', 'RWD',
        'V6', 'V8', 'V10', 'V12', 'LPG',
        // Genesis model codes
        'G70', 'G80', 'G90', 'GV70', 'GV80', 'GV90', 'EQ900',
        // Volvo model codes
        'XC40', 'XC60', 'XC90', 'S60', 'S90', 'V60', 'V90', 'C40',
    ];

    private ?array $termSets = null;

    private function getTermSets(): array
    {
        if ($this->termSets === null) {
            $this->termSets = app(TaxonomyTermService::class)->getSets('encar');
        }
        return $this->termSets;
    }

    public function handle(TaxonomyRuleEngine $ruleEngine, TaxonomySuggestionService $suggestions, TaxonomyTermService $termService): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(100, (int) $this->option('chunk'));
        $source = (string) $this->option('source');

        $random = (bool) $this->option('random');

        $this->info('Normalize Encar Taxonomy');
        $this->line('Mode: ' . ($apply ? 'APPLY' : 'DRY-RUN') . ($random ? ' [RANDOM SAMPLE]' : ''));
        $this->line("Source: {$source}, Chunk: {$chunk}, Limit: " . ($limit ?: 'all'));

        $termSets = $termService->getSets($source);

        $processed = 0;
        $wouldUpdate = 0;
        $updated = 0;
        $modelUpdates = 0;
        $trimUpdates = 0;
        $generationUpdates = 0;
        $unknownTailHits = [];
        $techSpecUpdates = 0;
        $variantUpdates = 0;
        $packageUpdates = 0;
        $samples = [];

        $baseQuery = DB::table('lots')
            ->where('source', $source)
            ->whereNotNull('model')
            ->where('model', '!=', '');

        if ($limit > 0) {
            $baseQuery->limit($limit);
        }

        $processRow = function ($rows) use (
            $apply,
            $limit,
            $ruleEngine,
            $suggestions,
            &$processed,
            &$wouldUpdate,
            &$updated,
            &$modelUpdates,
            &$trimUpdates,
            &$generationUpdates,
            &$unknownTailHits,
            &$techSpecUpdates,
            &$variantUpdates,
            &$packageUpdates,
            &$samples
        ) {
            foreach ($rows as $row) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }

                $processed++;

                $rawData = $this->decodeRawData($row->raw_data ?? null);
                $modelGroup = is_array($rawData) ? ($rawData['model_group_kr'] ?? null) : null;
                $badgeKr = is_array($rawData) ? (string) ($rawData['badge_kr'] ?? '') : '';

                [$normalizedModel, $generation, $inferredTrim, $inferredPackage, $unknownTail, $strippedTokens] = $this->normalizeModelTaxonomy((string) $row->model, $modelGroup);

                $rulePatch = $ruleEngine->apply([
                    'source'      => (string) $row->source,
                    'lot_id'      => (string) $row->id,
                    'make'        => (string) ($row->make ?? ''),
                    'model_raw'   => (string) $row->model,
                    'badge_raw'   => $badgeKr,
                    'model'       => $normalizedModel,
                    'generation'  => $generation,
                    'trim'        => $inferredTrim,
                    'unknown_tail' => $unknownTail,
                ], false);

                if (array_key_exists('model', $rulePatch)) {
                    $normalizedModel = (string) $rulePatch['model'];
                }
                if (array_key_exists('generation', $rulePatch)) {
                    $generation = $rulePatch['generation'];
                }
                if (array_key_exists('trim', $rulePatch)) {
                    $inferredTrim = $rulePatch['trim'];
                }
                if (array_key_exists('unknown_tail', $rulePatch)) {
                    $unknownTail = $rulePatch['unknown_tail'];
                }
                $ruleFuel = $rulePatch['fuel'] ?? null;
                $ruleDriveType = $rulePatch['drive_type'] ?? null;
                $ruleVariant = $rulePatch['variant'] ?? null;

                if ($unknownTail) {
                    $unknownTailHits[$unknownTail] = ($unknownTailHits[$unknownTail] ?? 0) + 1;
                    $this->upsertAnomalyQueue((string) $row->source, (string) ($row->make ?? ''), (string) $row->id, (string) $row->model, $unknownTail, $suggestions);
                }

                $heuristicVariant = $this->extractVariant($normalizedModel);
                if ($heuristicVariant !== null) {
                    $normalizedModel = trim(str_replace($heuristicVariant, '', $normalizedModel));
                    $normalizedModel = $this->normalizeSpace($normalizedModel);
                }
                $normalizedModel = $this->cleanTechSpecTokensFromModel($normalizedModel);

                $patch = [];

                $fullSearchStr = trim($row->model . ' ' . $badgeKr);
                $engineVol = $this->extractEngineVolume($fullSearchStr);
                $techSpecAdded = false;
                if ($ruleFuel !== null && ($row->fuel === null || $row->fuel === '')) {
                    $patch['fuel'] = $ruleFuel;
                    $techSpecAdded = true;
                }
                if ($ruleDriveType !== null && ($row->drive_type === null || $row->drive_type === '')) {
                    $patch['drive_type'] = $ruleDriveType;
                    $techSpecAdded = true;
                }
                if ($engineVol !== null && $row->engine_volume === null) {
                    $patch['engine_volume'] = $engineVol;
                    $techSpecAdded = true;
                }
                if ($techSpecAdded) {
                    $techSpecUpdates++;
                }
                if ($normalizedModel !== '' && $normalizedModel !== (string) $row->model) {
                    $patch['model'] = $normalizedModel;
                }

                if (($row->generation === null || $row->generation === '') && $generation) {
                    $patch['generation'] = $generation;
                }

                $trimCurrent = trim((string) ($row->trim ?? ''));
                $trimIsNull = $trimCurrent === '' || $trimCurrent === '(세부등급 없음)';

                // If trim is actually a generation code (e.g. E46, B8, 2세대) move it to generation
                $genCurrent = trim((string) ($row->generation ?? ''));
                if (!$trimIsNull && $genCurrent === '' && $this->trimIsGenerationCode($trimCurrent)) {
                    $patch['generation'] = $trimCurrent;
                    $patch['trim'] = null;
                    $trimIsNull = true;
                }

                if ($trimIsNull && $inferredTrim) {
                    $patch['trim'] = $inferredTrim;
                }

                $variantCurrent = trim((string) ($row->variant ?? ''));
                $resolvedVariant = $ruleVariant ?? $heuristicVariant;
                if ($variantCurrent === '' && $resolvedVariant !== null) {
                    $patch['variant'] = $resolvedVariant;
                }

                $packageCurrent = trim((string) ($row->package ?? ''));
                if ($packageCurrent === '' && $inferredPackage) {
                    $patch['package'] = $inferredPackage;
                }

                if ($patch === []) {
                    continue;
                }

                $wouldUpdate++;
                if (isset($patch['model'])) {
                    $modelUpdates++;
                }
                if (isset($patch['trim'])) {
                    $trimUpdates++;
                }
                if (isset($patch['generation'])) {
                    $generationUpdates++;
                }
                if (isset($patch['variant'])) {
                    $variantUpdates++;
                }
                if (isset($patch['package'])) {
                    $packageUpdates++;
                }

                if (count($samples) < 40) {
                    $samples[] = [
                        'id'             => $row->id,
                        'old_model'      => $row->model,
                        'new_model'      => $patch['model'] ?? $row->model,
                        'old_trim'       => $row->trim,
                        'new_trim'       => $patch['trim'] ?? $row->trim,
                        'old_generation' => $row->generation,
                        'new_generation' => $patch['generation'] ?? $row->generation,
                        'new_variant'    => $patch['variant'] ?? $row->variant ?? null,
                        'new_fuel'       => $patch['fuel'] ?? null,
                        'new_drive'      => $patch['drive_type'] ?? null,
                        'package_inferred' => $inferredPackage,
                        'unknown_tail'   => $unknownTail,
                    ];
                }

                if ($apply) {
                    $patch['updated_at'] = now();
                    DB::table('lots')->where('id', $row->id)->update($patch);
                    $updated++;
                }
            }

            return true;
        };

        $total = (clone $baseQuery)->count();
        $this->line("Total lots to process: {$total}");

        if ($random) {
            $processRow($baseQuery->inRandomOrder()->get());
        } else {
            $baseQuery->orderBy('id')->chunkById($chunk, function ($rows) use ($processRow, &$processed, $total) {
                $result = $processRow($rows);
                $pct = $total > 0 ? round($processed / $total * 100) : 0;
                $this->line("  [{$pct}%] processed {$processed}/{$total} ...");
                return $result;
            }, 'id', 'id');
        }

        $this->line('');
        $this->line("Processed: {$processed}");
        $this->line("Would update: {$wouldUpdate}");
        $this->line("Model updates: {$modelUpdates}");
        $this->line("Generation updates: {$generationUpdates}");
        $this->line("Trim updates: {$trimUpdates}");
        $this->line("Variant updates: {$variantUpdates}");
        $this->line("Package updates: {$packageUpdates}");
        $this->line("Tech spec updates: {$techSpecUpdates}");
        if ($apply) {
            $this->line("Updated: {$updated}");
        }

        if (!empty($samples)) {
            $this->line('');
            $this->line('Sample changes:');
            foreach ($samples as $sample) {
                $this->line(sprintf(
                    '#%s model: "%s" -> "%s" | trim: "%s" -> "%s" | gen: "%s" -> "%s"',
                    $sample['id'],
                    (string) $sample['old_model'],
                    (string) $sample['new_model'],
                    (string) ($sample['old_trim'] ?? ''),
                    (string) ($sample['new_trim'] ?? ''),
                    (string) ($sample['old_generation'] ?? ''),
                    (string) ($sample['new_generation'] ?? '')
                ));
                $extras = [];
                if (!empty($sample['new_variant'])) {
                    $extras[] = 'variant="' . $sample['new_variant'] . '"';
                }
                if (!empty($sample['new_fuel'])) {
                    $extras[] = 'fuel="' . $sample['new_fuel'] . '"';
                }
                if (!empty($sample['new_drive'])) {
                    $extras[] = 'drive="' . $sample['new_drive'] . '"';
                }
                if (!empty($sample['package_inferred'])) {
                    $extras[] = 'package="' . $sample['package_inferred'] . '"';
                }
                if (!empty($sample['unknown_tail'])) {
                    $extras[] = 'unknown_tail="' . $sample['unknown_tail'] . '"';
                }
                if (!empty($extras)) {
                    $this->line('    ' . implode(' | ', $extras));
                }
            }
        }

        if (!empty($unknownTailHits)) {
            arsort($unknownTailHits);
            $this->line('');
            $this->line('Top unknown tail candidates:');
            $shown = 0;
            foreach ($unknownTailHits as $tail => $count) {
                $this->line(sprintf('  %s => %d', $tail, $count));
                $shown++;
                if ($shown >= 25) {
                    break;
                }
            }
        }

        return self::SUCCESS;
    }

    private function upsertAnomalyQueue(string $source, string $make, string $lotId, string $modelRaw, string $unknownTail, TaxonomySuggestionService $suggestions): void
    {
        $source = trim($source) === '' ? 'encar' : trim($source);
        $make = $this->nullableString($make);
        $unknownTail = trim($unknownTail);
        if ($unknownTail === '') {
            return;
        }

        $reason = 'model_tail_not_matched_by_known_trim_package_patterns';
        $row = TaxonomyAnomalyQueue::query()
            ->where('source', $source)
            ->where('make', $make)
            ->where('unknown_tail', $unknownTail)
            ->where('reason', $reason)
            ->first();

        if ($row) {
            $row->seen_count = (int) $row->seen_count + 1;
            $row->last_seen_at = now();
            if ($row->sample_lot_id === null) {
                $row->sample_lot_id = $lotId;
            }
            if ($row->sample_model_raw === null) {
                $row->sample_model_raw = $modelRaw;
            }
            $row->save();
            return;
        }

        $s = $suggestions->suggest($unknownTail, $modelRaw);
        TaxonomyAnomalyQueue::query()->create([
            'source' => $source,
            'make' => $make,
            'unknown_tail' => $unknownTail,
            'reason' => $reason,
            'sample_lot_id' => $lotId,
            'sample_model_raw' => $modelRaw,
            'seen_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'status' => 'new',
            'suggested_action' => $s['action'],
            'suggested_value' => $s['value'],
            'suggestion_confidence' => $s['confidence'],
        ]);
    }

    private function nullableString(string $value): ?string
    {
        $v = trim($value);
        return $v === '' ? null : $v;
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

    private function normalizeModelTaxonomy(string $modelRaw, ?string $modelGroup): array
    {
        $prepared = $this->splitCompoundDriveTokens($this->normalizeSpace($modelRaw));
        [$modelNoNoise, $strippedTokens] = $this->stripTailNoiseWithTokens($prepared);
        [$modelNoGen, $generation] = $this->extractGeneration($modelNoNoise, $modelGroup);
        [$modelClean, $inferredTrim, $inferredPackage] = $this->splitModelTrimPackage($modelNoGen);
        $unknownTail = $this->detectUnknownTail($modelNoGen, $inferredTrim, $inferredPackage);

        $finalModel = $modelClean !== ''
            ? $modelClean
            : ($modelNoNoise !== '' ? $modelNoNoise : $this->normalizeSpace($modelRaw));

        return [$finalModel, $generation, $inferredTrim, $inferredPackage, $unknownTail, $strippedTokens];
    }

    private function splitCompoundDriveTokens(string $model): string
    {
        // Split BMW/Volvo compound tokens: xDrive40i → xDrive 40i, sDrive20i → sDrive 20i
        $result = preg_replace('/\b(xDrive|sDrive)(\d{2,3}[a-z]?)\b/u', '$1 $2', $model);
        return is_string($result) ? $result : $model;
    }

    private function normalizeSpace(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return is_string($value) ? $value : trim($value ?? '');
    }

    private function stripTailNoiseWithTokens(string $model): array
    {
        if ($model === '') {
            return ['', []];
        }

        $termSets = app(TaxonomyTermService::class)->getSets('encar');
        $tailTokens = $termSets['tail_powertrain_tokens'] ?? [];

        $tokens = preg_split('/\s+/u', $model) ?: [];
        $stripped = [];
        while (!empty($tokens)) {
            $tail = $tokens[count($tokens) - 1];
            if (
                in_array($tail, $tailTokens, true)
                || preg_match('/^\d{1,2}(?:\.\d+)?(?:T|D|L)?$/i', $tail)
                || preg_match('/^\d{1,2}인승$/u', $tail)
            ) {
                array_unshift($stripped, array_pop($tokens));
                continue;
            }
            break;
        }

        return [trim(implode(' ', $tokens)), $stripped];
    }

    private function cleanTechSpecTokensFromModel(string $model): string
    {
        $sets = $this->getTermSets();
        $tailTokens = $sets['tail_powertrain_tokens'] ?? [];
        $engineFamilyUpper = array_values(array_unique(array_merge(
            array_map('strtoupper', self::DEFAULT_ENGINE_FAMILY),
            array_map('strtoupper', $sets['engine_family_tokens'] ?? []),
        )));
        $tokens = preg_split('/\s+/u', $model) ?: [];
        $clean = [];
        foreach ($tokens as $t) {
            if (in_array(strtoupper($t), $engineFamilyUpper, true)) {
                continue;
            }
            if (in_array($t, $tailTokens, true)) {
                continue;
            }
            if (preg_match('/^\d+\.\d+[TDL]?$|^\d+[TDL]$/i', $t)) {
                continue;
            }
            if (in_array($t, self::BODY_STYLE_TOKENS, true)) {
                continue;
            }
            $clean[] = $t;
        }
        $result = trim(implode(' ', $clean));
        return $result !== '' ? $result : $model;
    }

    private function stripKnownWordsFromModel(string $model): string
    {
        $sets = $this->getTermSets();
        $tailTokens = $sets['tail_powertrain_tokens'] ?? [];
        $engineFamilyUpper = array_values(array_unique(array_merge(
            array_map('strtoupper', self::DEFAULT_ENGINE_FAMILY),
            array_map('strtoupper', $sets['engine_family_tokens'] ?? []),
        )));
        $tokens = preg_split('/\s+/u', $model) ?: [];
        $clean = [];
        foreach ($tokens as $t) {
            if (in_array(strtoupper($t), $engineFamilyUpper, true)) continue;
            if (in_array($t, $tailTokens, true)) continue;
            // Decimal engine displacements (2.0, 1.6T, 2.2d) — safe to strip, never model name parts
            if (preg_match('/^\d+\.\d+[TDLtdl]?$/', $t)) continue;
            $clean[] = $t;
        }
        $result = trim(implode(' ', $clean));
        return $result !== '' ? $result : $model;
    }

    private function extractVariant(string $model): ?string
    {
        $variantRe = '/^(?:[A-Z]{1,4}\d{2,4}[a-zA-Z]{0,2}|\d{2,4}[A-Za-z]{1,3})$/u';
        $sets = $this->getTermSets();
        $excludeUpper = array_values(array_unique(array_merge(
            array_map('strtoupper', self::DEFAULT_VARIANT_EXCLUDE),
            array_map('strtoupper', $sets['variant_exclude'] ?? []),
        )));
        $tokens = preg_split('/\s+/u', $model) ?: [];

        // Detect 2-token Audi-style variants: "{number} {TFSI|TDI|TSI}" e.g. "55 TFSI", "45 TFSI"
        $engineFamilyTwo = ['TFSI', 'TDI', 'TSI', 'CRDI', 'T-GDI'];
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            if (
                preg_match('/^\d{2,3}$/', $tokens[$i])
                && in_array(strtoupper($tokens[$i + 1]), $engineFamilyTwo, true)
            ) {
                return $tokens[$i] . ' ' . $tokens[$i + 1];
            }
        }

        foreach ($tokens as $tok) {
            if (in_array(strtoupper($tok), $excludeUpper, true)) {
                continue;
            }
            if (preg_match($variantRe, $tok)) {
                return $tok;
            }
        }
        return null;
    }

    private function extractEngineVolume(string $text): ?float
    {
        $tokens = preg_split('/\s+/', $text) ?: [];
        foreach ($tokens as $tok) {
            if (preg_match('/^(\d+(?:\.\d+)?)(?:T|D|L)?$/i', $tok, $m)) {
                $vol = (float) $m[1];
                if ($vol >= 0.5 && $vol <= 10.0) {
                    return round($vol, 1);
                }
            }
        }
        return null;
    }

    private function extractGeneration(string $model, ?string $modelGroup): array
    {
        $cleaned = $this->normalizeSpace($model);
        $generation = null;

        if (preg_match('/\(([A-Za-z0-9]{2,6})\)/', $cleaned, $m)) {
            $generation = $m[1];
            $cleaned = trim((string) preg_replace('/\(([A-Za-z0-9]{2,6})\)/', '', $cleaned));
            $cleaned = $this->normalizeSpace($cleaned);
        }

        if ($generation === null && $modelGroup) {
            $parts = preg_split('/\s+/', str_replace('/', ' ', $modelGroup)) ?: [];
            foreach ($parts as $part) {
                if ($this->isGenerationToken($part) && !$this->looksLikeModelPrefix($cleaned, $part)) {
                    $generation = $part;
                    break;
                }
            }
        }

        if ($generation === null && $cleaned !== '') {
            $parts = preg_split('/\s+/', $cleaned) ?: [];
            foreach ($parts as $i => $part) {
                if ($this->isGenerationToken($part) && !$this->looksLikeModelPrefix($cleaned, $part)) {
                    $generation = $part;
                    unset($parts[$i]);
                    $cleaned = $this->normalizeSpace(implode(' ', $parts));
                    break;
                }
            }
        }

        if ($generation !== null && $cleaned !== '') {
            $parts = preg_split('/\s+/', $cleaned) ?: [];
            $parts = array_values(array_filter($parts, fn (string $t): bool => $t !== $generation));
            $cleaned = $this->normalizeSpace(implode(' ', $parts));
        }

        return [$cleaned, $generation];
    }

    private function isGenerationToken(string $token): bool
    {
        $t = trim($token);
        if ($t === '') {
            return false;
        }

        $upper = strtoupper($t);

        $sets = $this->getTermSets();
        $nonChassis = array_map('strtoupper', array_merge(
            self::DEFAULT_GEN_NON_CHASSIS,
            $sets['gen_non_chassis_tokens'] ?? [],
        ));
        if (in_array($upper, $nonChassis, true)) {
            return false;
        }

        $excludeTokens = array_map('strtoupper', array_merge(
            self::DEFAULT_GEN_EXCLUDE,
            $sets['gen_exclude_tokens'] ?? [],
        ));
        if (in_array($upper, $excludeTokens, true)) {
            return false;
        }

        if (preg_match('/^V\d{1,2}$/', $upper) === 1) {
            return false;
        }

        if (preg_match('/^Q\d$/', $upper) === 1) {
            return false;
        }

        return preg_match('/^[A-Z]{1,3}\d{1,3}$/', $upper) === 1;
    }

    private function looksLikeModelPrefix(string $modelText, string $candidate): bool
    {
        $text = $this->normalizeSpace($modelText);
        $cand = trim($candidate);
        if ($text === '' || $cand === '') {
            return false;
        }

        if ($text === $cand || str_starts_with($text, $cand . ' ')) {
            return true;
        }

        $stripped = $this->normalizeSpace((string) preg_replace(self::MODEL_PREFIX_RE, '', $text));

        return $stripped === $cand || str_starts_with($stripped, $cand . ' ');
    }

    private function extractSuffixHint(string $text, array $hints): array
    {
        $normalized = $this->normalizeSpace($text);
        if ($normalized === '') {
            return ['', null];
        }

        usort($hints, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($hints as $hint) {
            if ($normalized === $hint) {
                return [$normalized, null];
            }

            $suffix = ' ' . $hint;
            if (str_ends_with($normalized, $suffix)) {
                $base = trim(substr($normalized, 0, strlen($normalized) - strlen($suffix)));
                return [$base, $hint];
            }
        }

        return [$normalized, null];
    }

    private function splitModelTrimPackage(string $model): array
    {
        $normalized = $this->normalizeSpace($model);
        if ($normalized === '') {
            return ['', null, null];
        }

        $termSets = app(TaxonomyTermService::class)->getSets('encar');
        $packageHints = $termSets['package_hints'] ?? [];
        $trimHints = $termSets['trim_hints'] ?? [];

        [$baseAfterPackage, $package] = $this->extractSuffixHint($normalized, $packageHints);
        [$baseAfterTrim, $trim] = $this->extractSuffixHint($baseAfterPackage, $trimHints);

        return [$baseAfterTrim, $trim, $package];
    }

    private function detectUnknownTail(string $modelNoGen, ?string $inferredTrim, ?string $inferredPackage): ?string
    {
        if ($inferredTrim || $inferredPackage) {
            return null;
        }

        // Strip only known word tokens (drive/powertrain) — not numeric — to avoid eating model name digits like "아토 3"
        $cleaned = $this->stripKnownWordsFromModel($modelNoGen);
        $tokens = preg_split('/\s+/u', $cleaned) ?: [];
        if (count($tokens) < 2) {
            return null;
        }

        $cnt = count($tokens);
        // Check longest tail first (3 → 2 → 1) so "AMG 패키지 플러스" beats "패키지 플러스"
        if ($cnt >= 3) {
            $tail3 = implode(' ', array_slice($tokens, -3));
            if ($tail3 !== '' && preg_match(self::UNKNOWN_TAIL_HINT_RE, $tail3)) {
                return $tail3;
            }
        }
        $tail2 = implode(' ', array_slice($tokens, -2));
        $tail1 = $tokens[$cnt - 1] ?? '';
        if ($tail2 !== '' && preg_match(self::UNKNOWN_TAIL_HINT_RE, $tail2)) {
            return $tail2;
        }
        if ($tail1 !== '' && preg_match(self::UNKNOWN_TAIL_HINT_RE, $tail1)) {
            return $tail1;
        }

        return null;
    }

    private function trimIsGenerationCode(string $trim): bool
    {
        // Korean generation labels: 1세대, 2세대, 3세대 ...
        if (preg_match('/^\d+세대$/u', $trim)) {
            return true;
        }
        // Western chassis codes: E46, B8, W222, F10, 4G etc.
        // Must match generation pattern but not be a known variant or trim word
        if ($this->isGenerationToken($trim)) {
            return true;
        }
        return false;
    }
}
