<?php

namespace App\Console\Commands;

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

    private const TAIL_POWERTRAIN_TOKENS = [
        '가솔린', '디젤', '하이브리드', 'HEV', 'LPG', '전기', 'EV',
        '2WD', '4WD', 'AWD', 'FWD', 'RWD', 'xDrive', 'sDrive',
        '터보', 'TCe', 'TFSI', 'TDI', 'e-VGT', '(택시형)', '(렌터카)', '(영업용)',
    ];

    private const GEN_NON_CHASSIS_TOKENS = [
        'EV', 'HEV', 'PHEV', 'GDI', 'TDI', 'TFSI', 'MPI',
        'AWD', 'FWD', 'RWD', '4WD', '2WD',
    ];

    private const PACKAGE_HINTS = [
        'M 스포츠 플러스', 'M 퍼포먼스', 'M 스포츠',
        'AMG Line', 'GT Line', 'N Line', 'S line', 'xLine',
    ];

    private const TRIM_HINTS = [
        '캘리그래피 블랙에디션', '마스터즈 그래비티', '익스클루시브 스페셜',
        '프레스티지 스페셜', '노블레스 스페셜', '프리미엄 초이스',
        '캘리그래피', '인스퍼레이션', '익스클루시브', '프레스티지', '시그니처',
        '노블레스', '프리미엄', '모던', '스마트', '럭셔리',
        '르블랑', '고급형', '기본형', '비즈니스 2', '비즈니스 1', '모빌리티',
    ];

    private const UNKNOWN_TAIL_HINT_RE = '/(에디션|라인|스페셜|패키지|플러스|스타일|셀렉션)$/u';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(100, (int) $this->option('chunk'));
        $source = (string) $this->option('source');

        $this->info('Normalize Encar Taxonomy');
        $this->line('Mode: ' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line("Source: {$source}, Chunk: {$chunk}, Limit: " . ($limit ?: 'all'));

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

                if ($unknownTail) {
                    $unknownTailHits[$unknownTail] = ($unknownTailHits[$unknownTail] ?? 0) + 1;
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

        $tokens = preg_split('/\s+/u', $model) ?: [];
        while (!empty($tokens)) {
            $tail = $tokens[count($tokens) - 1];
            if (
                in_array($tail, self::TAIL_POWERTRAIN_TOKENS, true)
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
                if ($this->isGenerationToken($part)) {
                    $generation = $part;
                    break;
                }
            }
        }

        if ($generation === null && $cleaned !== '') {
            $parts = preg_split('/\s+/', $cleaned) ?: [];
            foreach ($parts as $i => $part) {
                if ($this->isGenerationToken($part)) {
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

        if (preg_match('/^[A-Z]{1,3}\d{1,3}$/', $t)) {
            return true;
        }

        return preg_match('/^[A-Z]{2,4}$/', $t) === 1
            && !in_array($t, self::GEN_NON_CHASSIS_TOKENS, true);
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

        [$baseAfterPackage, $package] = $this->extractSuffixHint($normalized, self::PACKAGE_HINTS);
        [$baseAfterTrim, $trim] = $this->extractSuffixHint($baseAfterPackage, self::TRIM_HINTS);

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
