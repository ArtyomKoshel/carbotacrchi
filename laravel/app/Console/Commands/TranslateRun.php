<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Unified AI translation command.
 *
 * Translates Korean field values → English and stores results in the
 * `translations` table (KR→EN cache). Before calling AI, checks the
 * table first — no duplicate API calls.
 *
 * Supported categories and their data sources:
 *
 *   model         catalog_models.model_kr       → translations.model + catalog_models.model_en
 *   model_group   catalog_models.model_group_kr → translations.model_group
 *   trim          lots.trim / catalog_badges     → translations.trim
 *   generation    lots.generation               → translations.generation
 *
 * Variant B context for 'model': provides make_en + model_group_en to AI
 * so it can produce accurate English names.
 *
 * Usage:
 *   php artisan translate:run --category=model --apply
 *   php artisan translate:run --category=trim,generation --apply
 *   php artisan translate:run --category=model --apply --limit=200 --batch=20
 *   php artisan translate:run --category=model              # dry-run
 */
class TranslateRun extends Command
{
    protected $signature = 'translate:run
        {--category=model : Comma-separated categories: model,model_group,trim,generation}
        {--source=encar   : Lot source filter}
        {--batch=20       : Items per AI API call}
        {--limit=500      : Max new items to translate per category}
        {--sleep=3        : Seconds between API calls}
        {--apply          : Save results to DB (default: dry-run)}';

    protected $description = 'AI-translate Korean field values → English, store in translations table';

    private string $apiKey;
    private string $apiUrl;
    private string $aiModel;

    public function __construct()
    {
        parent::__construct();
        $this->apiKey  = config('ai.api_key', '');
        $this->apiUrl  = config('ai.api_url', 'https://api.groq.com/openai/v1/chat/completions');
        $this->aiModel = config('ai.model', 'llama-3.3-70b-versatile');
    }

    public function handle(): int
    {
        if (! $this->apiKey) {
            $this->error('AI_API_KEY not configured.');
            return self::FAILURE;
        }

        $categories = array_filter(array_map('trim', explode(',', $this->option('category'))));
        $apply      = (bool) $this->option('apply');
        $source     = (string) $this->option('source');
        $batch      = (int) $this->option('batch');
        $limit      = (int) $this->option('limit');
        $sleep      = (int) $this->option('sleep');

        $mode = $apply ? 'APPLY' : 'DRY-RUN';
        $this->info("translate:run  mode={$mode}  categories=" . implode(',', $categories));
        $this->line('');

        foreach ($categories as $category) {
            $this->line("<fg=cyan>── Category: {$category}</>");
            $this->runCategory($category, $source, $batch, $limit, $sleep, $apply);
            $this->line('');
        }

        $this->info('Done.');
        return self::SUCCESS;
    }

    // ── Category dispatch ──────────────────────────────────────────────────────

    private function runCategory(
        string $category,
        string $source,
        int    $batch,
        int    $limit,
        int    $sleep,
        bool   $apply,
    ): void {
        $items = match ($category) {
            'model'        => $this->collectModelItems($source, $limit),
            'model_group'  => $this->collectModelGroupItems($limit),
            'trim'         => $this->collectTrimItems($source, $limit),
            'generation'   => $this->collectGenerationItems($source, $limit),
            default        => $this->collectGenericItems($category, $source, $limit),
        };

        if (empty($items)) {
            $this->line("   nothing to translate (all in cache or no data)");
            return;
        }

        $this->line("   items to translate: " . count($items));

        $total = 0;
        $saved = 0;

        foreach (array_chunk($items, $batch) as $i => $chunk) {
            $results = $this->callAI($category, $chunk);

            if ($results === null) {
                $this->warn("   batch {$i}: AI call failed, skipping");
                continue;
            }

            foreach ($chunk as $item) {
                $kr = $item['kr'];
                $en = $results[$kr] ?? null;
                $total++;

                if (! $en) {
                    $this->warn("   no translation: {$kr}");
                    continue;
                }

                $this->line("   " . mb_substr($kr, 0, 40) . " → {$en}");

                if ($apply) {
                    $this->saveTranslation($category, $kr, $en);

                    // For 'model': also update catalog_models.model_en
                    if ($category === 'model' && ! empty($item['catalog_model_id'])) {
                        DB::table('catalog_models')
                            ->where('id', $item['catalog_model_id'])
                            ->update(['model_en' => $en, 'updated_at' => now()]);
                    }

                    $saved++;
                }
            }

            if ($i + 1 < count(array_chunk($items, $batch)) && $sleep > 0) {
                sleep($sleep);
            }
        }

        $this->line("   translated: {$total}  saved: {$saved}");
    }

    // ── Data collectors ────────────────────────────────────────────────────────

    /**
     * Collect catalog_models rows where model_kr has no translations.model entry.
     * Returns rich context: make_en + model_group_en for Variant B prompts.
     */
    private function collectModelItems(string $source, int $limit): array
    {
        // Get distinct model_kr values already in translations
        $cached = DB::table('translations')
            ->where('category', 'model')
            ->whereNotNull('en')
            ->pluck('kr')
            ->flip()
            ->toArray();

        $rows = DB::table('catalog_models')
            ->whereNotNull('model_kr')
            ->where('model_kr', '!=', '')
            ->whereNull('model_en')           // not yet filled in catalog
            ->select(['id', 'model_kr', 'make_en', 'model_group_en'])
            ->orderBy('make_en')
            ->get();

        $items = [];
        foreach ($rows as $row) {
            if (isset($cached[$row->model_kr])) {
                continue; // already translated
            }
            if (count($items) >= $limit) {
                break;
            }
            $items[] = [
                'kr'               => $row->model_kr,
                'make_en'          => $row->make_en ?? '',
                'model_group_en'   => $row->model_group_en ?? '',
                'catalog_model_id' => $row->id,
            ];
        }

        return $items;
    }

    /** Collect model_group_kr values not yet in translations. */
    private function collectModelGroupItems(int $limit): array
    {
        $cached = DB::table('translations')
            ->where('category', 'model_group')
            ->whereNotNull('en')
            ->pluck('kr')->flip()->toArray();

        return DB::table('catalog_models')
            ->selectRaw('DISTINCT model_group_kr AS kr, make_en')
            ->whereNotNull('model_group_kr')
            ->where('model_group_kr', '!=', '')
            ->whereNull('model_group_en')
            ->get()
            ->filter(fn ($r) => ! isset($cached[$r->kr]))
            ->take($limit)
            ->map(fn ($r) => ['kr' => $r->kr, 'make_en' => $r->make_en ?? ''])
            ->values()->toArray();
    }

    /** Collect unique trim values from lots not yet in translations. */
    private function collectTrimItems(string $source, int $limit): array
    {
        $cached = DB::table('translations')
            ->where('category', 'trim')
            ->whereNotNull('en')
            ->pluck('kr')->flip()->toArray();

        return DB::table('lots')
            ->where('source', $source)
            ->whereNotNull('trim')
            ->where('trim', '!=', '')
            ->selectRaw('DISTINCT trim AS kr')
            ->get()
            ->filter(fn ($r) => ! isset($cached[$r->kr])
                && ! preg_match('/^\d+세대$/', $r->kr)  // skip generation values
                && ! preg_match('/^[A-Za-z0-9\s\-\.]+$/', $r->kr)) // skip already-English
            ->take($limit)
            ->map(fn ($r) => ['kr' => $r->kr])
            ->values()->toArray();
    }

    /** Collect Korean generation values (ordinals, marketing names) not in translations. */
    private function collectGenerationItems(string $source, int $limit): array
    {
        $cached = DB::table('translations')
            ->where('category', 'generation')
            ->whereNotNull('en')
            ->pluck('kr')->flip()->toArray();

        return DB::table('lots')
            ->where('source', $source)
            ->whereNotNull('generation')
            ->whereRaw("generation REGEXP '[가-힣]'") // only Korean values
            ->selectRaw('DISTINCT generation AS kr')
            ->get()
            ->filter(fn ($r) => ! isset($cached[$r->kr]))
            ->take($limit)
            ->map(fn ($r) => ['kr' => $r->kr])
            ->values()->toArray();
    }

    /** Generic: collect distinct values from lots.{column} not in translations. */
    private function collectGenericItems(string $category, string $source, int $limit): array
    {
        $validColumns = ['color', 'seat_color', 'make'];
        if (! in_array($category, $validColumns, true)) {
            $this->warn("   unknown category: {$category}");
            return [];
        }

        $cached = DB::table('translations')
            ->where('category', $category)
            ->whereNotNull('en')
            ->pluck('kr')->flip()->toArray();

        return DB::table('lots')
            ->where('source', $source)
            ->whereNotNull($category)
            ->where($category, '!=', '')
            ->whereRaw("{$category} REGEXP '[가-힣]'")
            ->selectRaw("DISTINCT {$category} AS kr")
            ->get()
            ->filter(fn ($r) => ! isset($cached[$r->kr]))
            ->take($limit)
            ->map(fn ($r) => ['kr' => $r->kr])
            ->values()->toArray();
    }

    // ── AI call ────────────────────────────────────────────────────────────────

    /**
     * Translate a batch of items. Returns map of kr → en.
     * Uses Variant B context for 'model' category.
     */
    private function callAI(string $category, array $items): ?array
    {
        $prompt = $this->buildPrompt($category, $items);
        if (! $prompt) {
            return null;
        }

        $maxRetries = 3;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::timeout(45)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type'  => 'application/json',
                    ])
                    ->post($this->apiUrl, [
                        'model'       => $this->aiModel,
                        'max_tokens'  => 800,
                        'temperature' => 0,
                        'messages'    => [
                            ['role' => 'system', 'content' => $prompt['system']],
                            ['role' => 'user',   'content' => $prompt['user']],
                        ],
                    ]);

                if ($response->status() === 429 && $attempt < $maxRetries) {
                    $wait = 2 ** $attempt * 3;
                    $this->warn("   rate limit — retry {$attempt}/{$maxRetries} in {$wait}s");
                    sleep($wait);
                    continue;
                }

                if (! $response->successful()) {
                    Log::warning("[translate:run] API {$response->status()}: {$response->body()}");
                    return null;
                }

                $content = $response->json('choices.0.message.content', '');
                return $this->parseResponse($content, $items);

            } catch (\Throwable $e) {
                Log::error("[translate:run] {$e->getMessage()}");
                if ($attempt < $maxRetries) {
                    sleep(2 ** $attempt);
                }
            }
        }

        return null;
    }

    // ── Prompt builders ────────────────────────────────────────────────────────

    private function buildPrompt(string $category, array $items): ?array
    {
        return match ($category) {
            'model'       => $this->promptModel($items),
            'model_group' => $this->promptModelGroup($items),
            'trim'        => $this->promptTrim($items),
            'generation'  => $this->promptGeneration($items),
            'color'       => $this->promptColor($items),
            'seat_color'  => $this->promptSeatColor($items),
            default       => null,
        };
    }

    /** Variant B: AI gets make_en + model_group_en context for accurate model names. */
    private function promptModel(array $items): array
    {
        $lines = '';
        foreach ($items as $item) {
            $ctx = trim("{$item['make_en']} {$item['model_group_en']}");
            $lines .= "- [{$ctx}] {$item['kr']}\n";
        }

        return [
            'system' => <<<'SYS'
You are a Korean automotive expert. Translate Korean car model names to English.

Rules:
1. Use make + model group as context clues (provided in brackets).
2. Korean prefixes: "더 뉴" → "The New", "올 뉴" → "All New", "뉴" → "New", "더" → "The".
3. Keep chassis codes as-is: "(NX4)", "(G30)", "(DN8)" etc.
4. Fuel/powertrain suffixes: "하이브리드" → "Hybrid", "플러그인 하이브리드" → "Plug-in Hybrid", "전기" → "Electric".
5. Generation ordinals: "N세대" → "Gen N".
6. Return ONLY valid JSON: {"results": {"KoreanText": "EnglishText", ...}}
7. No explanations outside the JSON.
SYS,
            'user' => "Translate these Korean car model names to English:\n\n{$lines}",
        ];
    }

    private function promptModelGroup(array $items): array
    {
        $lines = implode("\n", array_map(
            fn ($i) => "- [{$i['make_en']}] {$i['kr']}",
            $items
        ));

        return [
            'system' => 'Translate Korean car model group names to English. Return ONLY valid JSON: {"results": {"Korean": "English", ...}}',
            'user'   => "Translate:\n{$lines}",
        ];
    }

    private function promptTrim(array $items): array
    {
        $lines = implode("\n", array_map(fn ($i) => "- {$i['kr']}", $items));

        return [
            'system' => <<<'SYS'
Translate Korean car trim/grade names to English.

Common trims: 프레스티지 → Prestige, 노블레스 → Noblesse, 익스클루시브 → Exclusive,
모던 → Modern, 럭셔리 → Luxury, 시그니처 → Signature, 캘리그래피 → Calligraphy,
프리미엄 → Premium, 스마트 → Smart, 스탠다드 → Standard, 엘리트 → Elite,
인스퍼레이션 → Inspiration, 플러스 → Plus, 스페셜 → Special.

Return ONLY valid JSON: {"results": {"Korean": "English", ...}}
SYS,
            'user' => "Translate these trim names:\n{$lines}",
        ];
    }

    private function promptGeneration(array $items): array
    {
        $lines = implode("\n", array_map(fn ($i) => "- {$i['kr']}", $items));

        return [
            'system' => 'Translate Korean car generation names. "N세대" → "Gen N". Keep model names that are already descriptive. Return ONLY valid JSON: {"results": {"Korean": "English", ...}}',
            'user'   => "Translate:\n{$lines}",
        ];
    }

    private function promptColor(array $items): array
    {
        $lines = implode("\n", array_map(fn ($i) => "- {$i['kr']}", $items));

        return [
            'system' => 'Translate Korean exterior car color names to English color names. Return ONLY valid JSON: {"results": {"Korean": "English", ...}}',
            'user'   => "Translate:\n{$lines}",
        ];
    }

    private function promptSeatColor(array $items): array
    {
        $lines = implode("\n", array_map(fn ($i) => "- {$i['kr']}", $items));

        return [
            'system' => 'Translate Korean interior/seat color names to English. "X색 계열" means "X color family". Return ONLY valid JSON: {"results": {"Korean": "English", ...}}',
            'user'   => "Translate:\n{$lines}",
        ];
    }

    // ── Response parser ────────────────────────────────────────────────────────

    /**
     * Parse AI response into kr → en map.
     *
     * @param array<array{kr: string}> $items
     * @return array<string, string>
     */
    private function parseResponse(string $content, array $items): array
    {
        $text = trim($content);

        // Strip markdown code blocks
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $text, $m)) {
            $text = trim($m[1]);
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            // Try to find JSON in response
            if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
                $decoded = json_decode($m[0], true);
            }
        }

        if (! is_array($decoded)) {
            Log::warning("[translate:run] Could not parse AI response: {$text}");
            return [];
        }

        // Support both {"results": {...}} and flat {"Korean": "English"}
        $map = $decoded['results'] ?? $decoded;
        if (! is_array($map)) {
            return [];
        }

        // Normalise: key = Korean, value = English
        $result = [];
        foreach ($map as $k => $v) {
            if (is_string($k) && is_string($v) && $v !== '') {
                $result[$k] = $v;
            }
        }

        return $result;
    }

    // ── DB helpers ─────────────────────────────────────────────────────────────

    private function saveTranslation(string $category, string $kr, string $en): void
    {
        DB::table('translations')->upsert(
            [
                'category'   => $category,
                'kr'         => $kr,
                'en'         => $en,
                'source'     => 'ai',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            ['category', 'kr'],
            ['en', 'source', 'updated_at']
        );
    }
}
