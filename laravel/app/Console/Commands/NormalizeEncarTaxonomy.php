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
        {--source=encar : Source to process (kept for flexibility)}';

    protected $description = 'Normalize Encar model taxonomy: clean model, infer generation, and fill empty trim where possible';

    private const GEN_NON_CHASSIS_TOKENS = [
        'EV', 'HEV', 'PHEV', 'GDI', 'TDI', 'TFSI', 'MPI',
        'AWD', 'FWD', 'RWD', '4WD', '2WD',
    ];

    private const UNKNOWN_TAIL_HINT_RE = '/(에디션|라인|스페셜|패키지|플러스|스타일|셀렉션)$/u';
    private const MODEL_PREFIX_RE = '/^(?:더\s+뉴|더|올\s+뉴|올뉴|뉴|신형)\s+/u';

    public function handle(TaxonomyRuleEngine $ruleEngine, TaxonomySuggestionService $suggestions, TaxonomyTermService $termService): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(100, (int) $this->option('chunk'));
        $source = (string) $this->option('source');

        $this->info('Normalize Encar Taxonomy');
        $this->line('Mode: ' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line("Source: {$source}, Chunk: {$chunk}, Limit: " . ($limit ?: 'all'));

        $termSets = $termService->getSets($source);

        $processed = 0;
        $wouldUpdate = 0;
        $updated = 0;
        $modelUpdates = 0;
        $trimUpdates = 0;
        $generationUpdates = 0;
        $unknownTailHits = [];
        $samples = [];

        $baseQuery = DB::table('lots')
            ->where('source', $source)
            ->whereNotNull('model')
            ->where('model', '!=', '')
            ->orderBy('id');

        if ($limit > 0) {
            $baseQuery->limit($limit);
        }

        $baseQuery->chunkById($chunk, function ($rows) use (
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
            &$samples
        ) {
            foreach ($rows as $row) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }

                $processed++;

                $rawData = $this->decodeRawData($row->raw_data ?? null);
                $modelGroup = is_array($rawData) ? ($rawData['model_group_kr'] ?? null) : null;

                [$normalizedModel, $generation, $inferredTrim, $inferredPackage, $unknownTail] = $this->normalizeModelTaxonomy((string) $row->model, $modelGroup);

                $rulePatch = $ruleEngine->apply([
                    'source' => (string) $row->source,
                    'lot_id' => (string) $row->id,
                    'make' => (string) ($row->make ?? ''),
                    'model_raw' => (string) $row->model,
                    'model' => $normalizedModel,
                    'generation' => $generation,
                    'trim' => $inferredTrim,
                    'unknown_tail' => $unknownTail,
                ], false);

                $normalizedModel = $rulePatch['model'] ?? $normalizedModel;
                $generation = $rulePatch['generation'] ?? $generation;
                $inferredTrim = $rulePatch['trim'] ?? $inferredTrim;
                $unknownTail = $rulePatch['unknown_tail'] ?? $unknownTail;

                if ($unknownTail) {
                    $unknownTailHits[$unknownTail] = ($unknownTailHits[$unknownTail] ?? 0) + 1;
                    $this->upsertAnomalyQueue((string) $row->source, (string) ($row->make ?? ''), (string) $row->id, (string) $row->model, $unknownTail, $suggestions);
                }

                $patch = [];
                if ($normalizedModel !== '' && $normalizedModel !== (string) $row->model) {
                    $patch['model'] = $normalizedModel;
                }

                if (($row->generation === null || $row->generation === '') && $generation) {
                    $patch['generation'] = $generation;
                }

                $trimCurrent = trim((string) ($row->trim ?? ''));
                if ($trimCurrent === '' && $inferredTrim) {
                    $patch['trim'] = $inferredTrim;
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

                if (count($samples) < 40) {
                    $samples[] = [
                        'id' => $row->id,
                        'old_model' => $row->model,
                        'new_model' => $patch['model'] ?? $row->model,
                        'old_trim' => $row->trim,
                        'new_trim' => $patch['trim'] ?? $row->trim,
                        'old_generation' => $row->generation,
                        'new_generation' => $patch['generation'] ?? $row->generation,
                        'package_inferred' => $inferredPackage,
                        'unknown_tail' => $unknownTail,
                    ];
                }

                if ($apply) {
                    $patch['updated_at'] = now();
                    DB::table('lots')->where('id', $row->id)->update($patch);
                    $updated++;
                }
            }

            return true;
        }, 'id', 'id');

        $this->line('');
        $this->line("Processed: {$processed}");
        $this->line("Would update: {$wouldUpdate}");
        $this->line("Model updates: {$modelUpdates}");
        $this->line("Generation updates: {$generationUpdates}");
        $this->line("Trim updates: {$trimUpdates}");
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
                if (!empty($sample['package_inferred']) || !empty($sample['unknown_tail'])) {
                    $this->line(sprintf(
                        '    package="%s" unknown_tail="%s"',
                        (string) ($sample['package_inferred'] ?? ''),
                        (string) ($sample['unknown_tail'] ?? '')
                    ));
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
        $modelNoNoise = $this->stripTailNoise($this->normalizeSpace($modelRaw));
        [$modelNoGen, $generation] = $this->extractGeneration($modelNoNoise, $modelGroup);
        [$modelClean, $inferredTrim, $inferredPackage] = $this->splitModelTrimPackage($modelNoGen);
        $unknownTail = $this->detectUnknownTail($modelNoGen, $inferredTrim, $inferredPackage);

        $finalModel = $modelClean !== ''
            ? $modelClean
            : ($modelNoNoise !== '' ? $modelNoNoise : $this->normalizeSpace($modelRaw));

        return [$finalModel, $generation, $inferredTrim, $inferredPackage, $unknownTail];
    }

    private function normalizeSpace(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return is_string($value) ? $value : trim($value ?? '');
    }

    private function stripTailNoise(string $model): string
    {
        if ($model === '') {
            return '';
        }

        $termSets = app(TaxonomyTermService::class)->getSets('encar');
        $tailTokens = $termSets['tail_powertrain_tokens'] ?? [];

        $tokens = preg_split('/\s+/u', $model) ?: [];
        while (!empty($tokens)) {
            $tail = $tokens[count($tokens) - 1];
            if (
                in_array($tail, $tailTokens, true)
                || preg_match('/^\d+(?:\.\d+)?(?:T|D|L)?$/i', $tail)
                || preg_match('/^\d{1,2}인승$/u', $tail)
            ) {
                array_pop($tokens);
                continue;
            }
            break;
        }

        return trim(implode(' ', $tokens));
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

        return preg_match('/^[A-Z]{1,3}\d{1,3}$/', $t) === 1;
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

        $tokens = preg_split('/\s+/u', $modelNoGen) ?: [];
        if (count($tokens) < 2) {
            return null;
        }

        $tail2 = implode(' ', array_slice($tokens, -2));
        $tail1 = $tokens[count($tokens) - 1] ?? '';
        if ($tail2 !== '' && preg_match(self::UNKNOWN_TAIL_HINT_RE, $tail2)) {
            return $tail2;
        }
        if ($tail1 !== '' && preg_match(self::UNKNOWN_TAIL_HINT_RE, $tail1)) {
            return $tail1;
        }

        return null;
    }
}
