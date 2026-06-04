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
 * Translates distinct Korean values from lots columns → lots.*_en columns.
 * Caches results in `translations` table (category + kr/en pair).
 *
 * Supported categories:
 *   make          lots.make        → lots.make_en
 *   model         lots.model       → lots.model_en        (with make+model_group context)
 *   model_group   lots.model_group → lots.model_group_en
 *   badge_group   lots.badge_group → lots.badge_group_en
 *   trim          lots.trim        → lots.trim_en
 *   color         lots.color       → lots.color (overwrites Korean)
 *   seat_color    lots.seat_color  → lots.seat_color (overwrites Korean)
 *
 * Usage:
 *   php artisan translate:run --category=model --apply
 *   php artisan translate:run --category=model,model_group,badge_group,trim --apply
 *   php artisan translate:run --category=model --apply --limit=200 --batch=20
 *   php artisan translate:run --category=model              # dry-run
 */
class TranslateRun extends Command
{
    protected $signature = 'translate:run
        {--category=model : Comma-separated categories: make,model,model_group,badge_group,trim,color,seat_color}
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
            'model_group'  => $this->collectLotsDistinct('model_group', $source, $limit, fn ($r) => ['kr' => $r->kr, 'make_en' => $r->make_en ?? '']),
            'badge_group'  => $this->collectLotsDistinct('badge_group', $source, $limit),
            'badge'        => $this->collectLotsDistinct('badge', $source, $limit),
            'trim'         => $this->collectTrimItems($source, $limit),
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
                    $saved++;
                }
            }

            if ($i + 1 < count(array_chunk($items, $batch)) && $sleep > 0) {
                sleep($sleep);
            }
        }

        $this->line("   translated: {$total}  saved: {$saved}");

        // Apply translations back to lots.*_en columns in bulk
        if ($apply && $saved > 0) {
            $affected = $this->applyToLots($category, $source);
            if ($affected > 0) {
                $this->line("   → applied to lots: {$affected} rows");
            }
        }
    }

    /**
     * Bulk UPDATE lots.{en_col} from translations table after AI run.
     */
    private function applyToLots(string $category, string $source): int
    {
        $map = [
            'make'        => ['kr_col' => 'make',        'en_col' => 'make_en'],
            'model'       => ['kr_col' => 'model',       'en_col' => 'model_en'],
            'model_group' => ['kr_col' => 'model_group', 'en_col' => 'model_group_en'],
            'badge_group' => ['kr_col' => 'badge_group', 'en_col' => 'badge_group_en'],
            'badge'       => ['kr_col' => 'badge',       'en_col' => 'badge_en'],
            'trim'        => ['kr_col' => 'trim',        'en_col' => 'trim_en'],
        ];

        if (! isset($map[$category])) {
            return 0;
        }

        $krCol = $map[$category]['kr_col'];
        $enCol = $map[$category]['en_col'];

        DB::statement("
            UPDATE lots l
            JOIN translations t ON t.category = ? AND t.kr = l.{$krCol}
            SET l.{$enCol}  = t.en,
                l.updated_at = NOW()
            WHERE l.source = ?
              AND l.{$krCol}  IS NOT NULL
              AND l.{$krCol}  != ''
              AND (l.{$enCol} IS NULL OR l.{$enCol} = '')
              AND t.en IS NOT NULL
        ", [$category, $source]);

        return DB::affectedRows();
    }

    // ── Data collectors ────────────────────────────────────────────────────────

    /**
     * Collect distinct lots.model values where model_en is missing.
     * Returns make_en + model_group_en context for accurate AI translation.
     */
    private function collectModelItems(string $source, int $limit): array
    {
        $cached = DB::table('translations')
            ->where('category', 'model')
            ->whereNotNull('en')
            ->pluck('kr')->flip()->toArray();

        $rows = DB::table('lots')
            ->where('source', $source)
            ->whereNotNull('model')->where('model', '!=', '')
            ->whereNull('model_en')
            ->selectRaw('model AS kr, MAX(make_en) AS make_en, MAX(model_group_en) AS model_group_en')
            ->groupBy('model')
            ->orderBy('model')
            ->get();

        $items = [];
        foreach ($rows as $row) {
            if (isset($cached[$row->kr])) {
                continue;
            }
            if (count($items) >= $limit) {
                break;
            }
            $items[] = [
                'kr'             => $row->kr,
                'make_en'        => $row->make_en        ?? '',
                'model_group_en' => $row->model_group_en ?? '',
            ];
        }

        return $items;
    }

    /**
     * Collect distinct values from lots.{column} not yet in translations.
     * Optional $itemMapper transforms each DB row into the item array.
     *
     * @param callable|null $itemMapper  fn(object $row): array
     */
    private function collectLotsDistinct(
        string   $column,
        string   $source,
        int      $limit,
        ?callable $itemMapper = null,
    ): array {
        $cached = DB::table('translations')
            ->where('category', $column)
            ->whereNotNull('en')
            ->pluck('kr')->flip()->toArray();

        $query = DB::table('lots')
            ->where('source', $source)
            ->whereNotNull($column)->where($column, '!=', '')
            ->selectRaw("{$column} AS kr, MAX(make_en) AS make_en")
            ->groupBy($column)
            ->orderBy($column);

        return $query->get()
            ->filter(fn ($r) => ! isset($cached[$r->kr]))
            ->take($limit)
            ->map($itemMapper ?? fn ($r) => ['kr' => $r->kr])
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

    /** Fallback: collect distinct Korean values from lots.{column}. */
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
            'badge_group' => $this->promptBadgeGroup($items),
            'badge'       => $this->promptBadge($items),
            'trim'        => $this->promptTrim($items),
            'make'        => $this->promptMake($items),
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

    private function promptBadgeGroup(array $items): array
    {
        $lines = implode("\n", array_map(fn ($i) => "- {$i['kr']}", $items));

        return [
            'system' => <<<'SYS'
Translate Korean car BadgeGroup names to English. BadgeGroup describes the engine/fuel variant group.

Examples: 가솔린 3500cc → Gasoline 3500cc, 디젤 2000cc → Diesel 2000cc,
전기 → Electric, 가솔린 2.5T → Gasoline 2.5T, 하이브리드 → Hybrid,
LPG → LPG, 가솔린 터보 → Gasoline Turbo.

Return ONLY valid JSON: {"results": {"Korean": "English", ...}}
SYS,
            'user' => "Translate:\n{$lines}",
        ];
    }

    private function promptBadge(array $items): array
    {
        $lines = implode("\n", array_map(fn ($i) => "- {$i['kr']}", $items));

        return [
            'system' => <<<'SYS'
Translate Korean car Badge strings to English. A Badge is the full variant label combining engine, drivetrain, seats, and trim (e.g. "가솔린 7인승 아웃도어" → "Gasoline 7-seater Outdoor").

Common patterns: 가솔린 → Gasoline, 디젤 → Diesel, 전기 → Electric, 하이브리드 → Hybrid,
인승 → seater (7인승 → 7-seater), 콰트로 → Quattro, 아웃도어 → Outdoor,
스포트백 → Sportback, 터보 → Turbo, AWD/4WD → keep as-is.

Return ONLY valid JSON: {"results": {"Korean": "English", ...}}
SYS,
            'user' => "Translate:\n{$lines}",
        ];
    }

    private function promptMake(array $items): array
    {
        $lines = implode("\n", array_map(fn ($i) => "- {$i['kr']}", $items));

        return [
            'system' => 'Translate Korean car manufacturer names to English. Return ONLY valid JSON: {"results": {"Korean": "English", ...}}',
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
