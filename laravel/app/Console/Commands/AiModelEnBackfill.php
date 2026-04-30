<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI-batch mapping for model_en (Korean → English).
 *
 * Queries lots with NULL model_en, uses AI to translate Korean model names to English,
 * and updates the database. Can also generate new mapping entries for korean_model_names.py.
 */
class AiModelEnBackfill extends Command
{
    protected $signature = 'model-en:ai-backfill {--limit=100 : Maximum number of lots to process} {--batch=5 : Batch size for AI requests (default 5 to avoid rate limits)} {--dry-run : Show what would be done without making changes} {--source= : Only process specific source (kbcha/encar)} {--generate-mapping : Generate Python mapping entries}';
    protected $description = 'Use AI to backfill model_en from Korean model names';

    private string $apiKey;
    private string $apiUrl;
    private string $model;

    public function __construct()
    {
        parent::__construct();
        $this->apiKey = config('ai.api_key', '');
        $this->apiUrl = config('ai.api_url', 'https://api.groq.com/openai/v1/chat/completions');
        $this->model  = config('ai.model', 'llama-3.3-70b-versatile');
    }

    public function handle(): int
    {
        if (!$this->apiKey) {
            $this->error('AI API key not configured. Set AI_API_KEY in .env');
            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $batchSize = (int) $this->option('batch');
        $dryRun = $this->option('dry-run');
        $source = $this->option('source');
        $generateMapping = $this->option('generate-mapping');

        $this->info("AI Model EN Backfill");
        $this->line("Limit: {$limit}, Batch size: {$batchSize}");
        $this->line("Dry run: " . ($dryRun ? 'yes' : 'no'));
        if ($source) {
            $this->line("Source: {$source}");
        }

        // Query lots with NULL model_en
        $query = DB::table('lots')
            ->whereNull('model_en')
            ->whereNotNull('model')
            ->where('model', '!=', '')
            ->where('is_active', 1);

        if ($source) {
            $query->where('source', $source);
        }

        $total = $query->count();
        $this->line("Found {$total} lots with NULL model_en");

        if ($total === 0) {
            $this->info('Nothing to process.');
            return self::SUCCESS;
        }

        $lots = $query->limit($limit)->get(['id', 'model', 'source']);
        $this->line("Processing " . $lots->count() . " lots");

        $mappingEntries = [];
        $processed = 0;
        $success = 0;
        $failed = 0;

        // Process in batches
        foreach ($lots->chunk($batchSize) as $chunk) {
            $this->line("\nProcessing batch of {$chunk->count()}...");

            // Prepare batch request
            $batchData = [];
            foreach ($chunk as $lot) {
                $batchData[] = [
                    'id' => $lot->id,
                    'model' => $lot->model,
                    'source' => $lot->source,
                ];
            }

            // Call AI for batch translation
            $translations = $this->translateBatch($batchData);

            if (!$translations) {
                $this->error('AI translation failed for this batch');
                $failed += $chunk->count();
                continue;
            }

            // Apply translations
            foreach ($chunk as $lot) {
                $id = $lot->id;
                $modelKorean = $lot->model;
                $modelEn = $translations[$id] ?? null;

                if (!$modelEn) {
                    $this->warn("  [{$id}] No translation for: {$modelKorean}");
                    $failed++;
                    continue;
                }

                $this->info("  [{$id}] {$modelKorean} → {$modelEn}");

                if (!$dryRun) {
                    DB::table('lots')
                        ->where('id', $id)
                        ->update(['model_en' => $modelEn]);
                }

                if ($generateMapping) {
                    $mappingEntries[] = [
                        'korean' => $modelKorean,
                        'english' => $modelEn,
                        'source' => $lot->source,
                    ];
                }

                $success++;
            }

            $processed += $chunk->count();
            $this->line("  Batch complete. Success: {$success}, Failed: {$failed}");
        }

        $this->line("\n=== Summary ===");
        $this->line("Processed: {$processed}");
        $this->line("Success: {$success}");
        $this->line("Failed: {$failed}");

        if ($generateMapping && !empty($mappingEntries)) {
            $this->generatePythonMapping($mappingEntries);
        }

        return self::SUCCESS;
    }

    /**
     * Translate a batch of Korean model names to English using AI.
     *
     * @param array<array{id: int, model: string, source: string}> $batch
     * @return array<int, string>|null Map of lot_id -> English model name
     */
    private function translateBatch(array $batch): ?array
    {
        $maxRetries = 5;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $attempt++;
            $result = $this->translateBatchAttempt($batch, $attempt);

            if ($result !== null) {
                return $result;
            }

            // Check if we should retry
            if ($attempt < $maxRetries) {
                $waitTime = pow(2, $attempt) * 2; // 4, 8, 16, 32 seconds
                $this->warn("  Rate limit hit, waiting {$waitTime}s before retry {$attempt}/{$maxRetries}...");
                sleep($waitTime);
            }
        }

        return null;
    }

    /**
     * Single attempt to translate a batch.
     *
     * @param array<array{id: int, model: string, source: string}> $batch
     * @param int $attempt
     * @return array<int, string>|null
     */
    private function translateBatchAttempt(array $batch, int $attempt): ?array
    {
        // Build prompt with all models
        $modelsText = '';
        foreach ($batch as $item) {
            $modelsText .= "{$item['id']}: {$item['model']}\n";
        }

        $systemPrompt = <<<'PROMPT'
You are a Korean car model name translator. Translate Korean car model names to their canonical English names.

Rules:
1. Return ONLY valid JSON, no explanations
2. Format: {"translations": [{"id": 123, "en": "Tucson"}, ...]}
3. Use canonical English model names (e.g., "투싼" → "Tucson", "스포티지" → "Sportage")
4. If the model is already in English, return it as-is
5. If you cannot determine the English name, set "en" to null
6. For Korean domestic brands (Hyundai, Kia, Genesis, Renault Korea, KG Mobility), use official English names
7. For foreign brands, use the standard international model names
8. Include trim/variant info only if it's part of the canonical name (e.g., "3 Series GT")
PROMPT;

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->post($this->apiUrl, [
                    'model'       => $this->model,
                    'max_tokens'  => 1000,
                    'temperature' => 0,
                    'messages'    => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => "Translate these Korean car model names:\n\n{$modelsText}"],
                    ],
                ]);

            if (!$response->successful()) {
                $status = $response->status();
                $body = $response->body();

                if ($status === 429) {
                    Log::warning("[AiModelEnBackfill] Rate limit hit (attempt {$attempt}): {$body}");
                    return null; // Signal to retry
                }

                Log::warning("[AiModelEnBackfill] API error: {$status} {$body}");
                return null; // Don't retry on other errors
            }

            $content = $response->json('choices.0.message.content', '');
            $json = $this->extractJson($content);

            if (!$json || !isset($json['translations'])) {
                Log::warning('[AiModelEnBackfill] Invalid JSON response: ' . $content);
                return null;
            }

            // Build map of id -> en
            $result = [];
            foreach ($json['translations'] as $item) {
                $id = (int) $item['id'];
                $en = $item['en'] ?? null;
                if ($en) {
                    $result[$id] = $en;
                }
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('[AiModelEnBackfill] ' . $e->getMessage());
            return null;
        }
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        // Try to extract JSON from markdown code blocks
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $text, $m)) {
            $text = trim($m[1]);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Try to find JSON object in text
        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Generate Python mapping entries for korean_model_names.py
     *
     * @param array<array{korean: string, english: string, source: string}> $entries
     */
    private function generatePythonMapping(array $entries): void
    {
        $this->line("\n=== Python Mapping Entries ===");

        // Group by Korean substring (deduplicate)
        $uniqueMappings = [];
        foreach ($entries as $entry) {
            $kr = $entry['korean'];
            $en = $entry['english'];
            $key = strtolower($kr);
            if (!isset($uniqueMappings[$key])) {
                $uniqueMappings[$key] = ['kr' => $kr, 'en' => $en, 'sources' => []];
            }
            $uniqueMappings[$key]['sources'][] = $entry['source'];
        }

        foreach ($uniqueMappings as $item) {
            $kr = $item['kr'];
            $en = $item['en'];
            $sources = array_unique($item['sources']);
            $sourceStr = implode(', ', $sources);
            $this->line("    \"{$kr}\": \"{$en}\",  # {$sourceStr}");
        }

        $this->line("\nAdd these entries to parser/parsers/_shared/korean_model_names.py");
    }
}
