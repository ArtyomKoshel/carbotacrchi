<?php

namespace App\Console\Commands;

use App\Models\TaxonomyAnomalyQueue;
use App\Models\TaxonomyRule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiClassifyPatterns extends Command
{
    protected $signature = 'taxonomy:ai-classify-patterns
        {--source=encar   : Source filter}
        {--make=          : Filter by make (optional)}
        {--limit=50       : Number of unique patterns to process}
        {--confidence=90  : Auto-create rule threshold (0-100)}
        {--dry-run        : Show results without saving}
        {--sleep=4        : Seconds to sleep between API calls (default 4 to stay under TPM limit)}';

    protected $description = 'AI-classify unique (make, model_kr_raw, badge_kr) patterns → auto-create rules or queue for review';

    private string $apiKey;
    private string $apiUrl;
    private string $aiModel;

    public function handle(): int
    {
        $this->apiKey   = config('ai.api_key', '');
        $this->apiUrl   = config('ai.api_url', '');
        $this->aiModel  = config('ai.model', '');

        if (empty($this->apiKey)) {
            $this->error('AI_API_KEY not configured.');
            return 1;
        }

        $source     = $this->option('source');
        $makeFilter = trim((string) $this->option('make'));
        $limit      = max(1, (int) $this->option('limit'));
        $threshold  = max(0, min(100, (int) $this->option('confidence')));
        $dryRun     = (bool) $this->option('dry-run');
        $sleep      = max(0, (int) $this->option('sleep'));

        $this->info('AI Classify Patterns');
        $this->line("Mode: " . ($dryRun ? 'DRY-RUN' : 'APPLY'));
        $this->line("Source: {$source} | Limit: {$limit} | Auto-rule confidence >= {$threshold}%");
        $this->line('');

        $query = DB::table('lots')
            ->select([
                'make',
                DB::raw("COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.model_kr_raw')),
                    JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.model_kr')),
                    model
                ) AS model_raw"),
                DB::raw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.badge_kr')), '') AS badge_raw"),
                DB::raw('COUNT(*) AS cnt'),
            ])
            ->where('source', $source)
            ->whereNotNull('model')
            ->where('model', '!=', '')
            ->groupBy('make', 'model_raw', 'badge_raw')
            ->orderByDesc('cnt')
            ->limit($limit);

        if ($makeFilter !== '') {
            $query->where('make', $makeFilter);
        }

        $patterns = $query->get();

        if ($patterns->isEmpty()) {
            $this->warn('No patterns found.');
            return 0;
        }

        $this->line("Found {$patterns->count()} unique patterns to classify.");
        $this->line('');

        $rulesCreated  = 0;
        $queued        = 0;
        $failed        = 0;

        foreach ($patterns as $pattern) {
            $make     = (string) ($pattern->make ?? '');
            $modelRaw = (string) ($pattern->model_raw ?? '');
            $badgeRaw = (string) ($pattern->badge_raw ?? '');
            $cnt      = (int) ($pattern->cnt ?? 1);

            if ($modelRaw === '' || $modelRaw === 'null') {
                continue;
            }

            $this->line("  [{$cnt}x] make={$make} | model=\"{$modelRaw}\" | badge=\"{$badgeRaw}\"");

            $result = $this->classifyPattern($make, $modelRaw, $badgeRaw);

            if ($sleep > 0) {
                sleep($sleep);
            }

            if ($result === null) {
                $this->warn("    → AI error, skipping");
                $failed++;
                continue;
            }

            $confidence  = (int) round((float) ($result['confidence'] ?? 0));
            $modelClean  = trim((string) ($result['model_clean'] ?? ''));
            $generation  = $this->nullOrString($result['generation'] ?? null);
            $trim        = $this->nullOrString($result['trim'] ?? null);
            $variant     = $this->nullOrString($result['variant'] ?? null);
            $package     = $this->nullOrString($result['package'] ?? null);
            $bodyType    = $this->nullOrString($result['body_type'] ?? null);
            $notes       = trim((string) ($result['notes'] ?? ''));
            $debug       = $result['debug'] ?? [];

            $knowledgeFlags = implode(', ', array_keys(array_filter((array) $debug)));
            $this->line("    → conf={$confidence}% model_clean=\"{$modelClean}\" trim=\"{$trim}\" variant=\"{$variant}\" body=\"{$bodyType}\"");
            if ($notes) $this->line("    → notes: {$notes}");
            if ($knowledgeFlags) $this->line("    → knowledge used: {$knowledgeFlags}");

            if ($confidence >= $threshold) {
                if (!$dryRun) {
                    $created = $this->createRules($source, $make, $modelRaw, $modelClean, $generation, $trim, $variant, $package, $bodyType, $confidence);
                    $rulesCreated += $created;
                    $this->line("    → AUTO-CREATED {$created} rule(s)");
                } else {
                    $this->line("    → [dry-run] would create rules");
                }
            } else {
                if (!$dryRun) {
                    $this->queueForReview($source, $make, $modelRaw, $badgeRaw, $trim, $variant, $package, $confidence);
                    $queued++;
                    $this->line("    → QUEUED for review");
                } else {
                    $this->line("    → [dry-run] would queue for review");
                }
            }
        }

        $this->line('');
        $this->info("Done. Rules created: {$rulesCreated} | Queued: {$queued} | Errors: {$failed}");

        if ($rulesCreated > 0 && !$dryRun) {
            $this->line('');
            $this->line('Run normalize to apply new rules:');
            $this->line('  php artisan lots:normalize-encar-taxonomy --source=' . $source . ' --chunk=2000 --apply');
        }

        return 0;
    }

    private function classifyPattern(string $make, string $modelRaw, string $badgeRaw, int $attempt = 0): ?array
    {
        $input = json_encode([
            'make'      => $make,
            'model_raw' => $modelRaw,
            'badge_raw' => $badgeRaw !== '' ? $badgeRaw : null,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->post($this->apiUrl, [
                    'model'           => $this->aiModel,
                    'max_tokens'      => 400,
                    'temperature'     => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => [
                        ['role' => 'system', 'content' => $this->buildSystemPrompt()],
                        ['role' => 'user',   'content' => $input],
                    ],
                ]);

            if ($response->status() === 429 && $attempt < 3) {
                $waitSec = $this->parseRetryAfter($response->body());
                $this->line("    ⏳ Rate limited, waiting {$waitSec}s...");
                sleep($waitSec);
                return $this->classifyPattern($make, $modelRaw, $badgeRaw, $attempt + 1);
            }

            if (!$response->successful()) {
                Log::warning('[AiClassifyPatterns] API error: ' . $response->status() . ' ' . $response->body());
                return null;
            }

            $content = $response->json('choices.0.message.content', '');
            $json    = json_decode($content, true);
            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::error('[AiClassifyPatterns] ' . $e->getMessage());
            return null;
        }
    }

    private function parseRetryAfter(string $body): int
    {
        // Parse "Please try again in 1.565s" from Groq error
        if (preg_match('/try again in (\d+\.?\d*)s/', $body, $m)) {
            return (int) ceil((float) $m[1]) + 1;
        }
        return 10;
    }

    private function createRules(
        string $source, string $make, string $modelRaw, string $modelClean,
        ?string $generation, ?string $trim, ?string $variant,
        ?string $package, ?string $bodyType,
        int $confidence
    ): int {
        $created = 0;
        $notes   = "ai_classify_patterns conf={$confidence}%";

        $ruleBase = [
            'source'         => $source,
            'make'           => $make ?: null,
            'model_contains' => $modelRaw,
            'priority'       => 85,
            'is_active'      => true,
            'notes'          => $notes,
        ];

        $actions = [];

        if ($trim !== null && $trim !== '') {
            $actions[] = ['action' => 'set_trim',       'action_value' => $trim];
        }
        if ($variant !== null && $variant !== '') {
            $actions[] = ['action' => 'set_variant',    'action_value' => $variant];
        }
        if ($package !== null && $package !== '') {
            $actions[] = ['action' => 'set_package',    'action_value' => $package];
        }
        if ($generation !== null && $generation !== '') {
            $actions[] = ['action' => 'set_generation', 'action_value' => $generation];
        }
        if ($bodyType !== null && $bodyType !== '') {
            $actions[] = ['action' => 'set_body_type',  'action_value' => $bodyType];
        }
        // NOTE: fuel/drive_type/engine_volume are handled by deterministic rules (encar_rules_seed.sql)

        foreach ($actions as $actionData) {
            $exists = TaxonomyRule::query()
                ->where('source', $source)
                ->where('make', $make ?: null)
                ->where('model_contains', $modelRaw)
                ->where('action', $actionData['action'])
                ->where('is_active', true)
                ->exists();

            if (!$exists) {
                TaxonomyRule::create(array_merge($ruleBase, $actionData));
                $created++;
            }
        }

        return $created;
    }

    private function queueForReview(
        string $source, string $make, string $modelRaw, string $badgeRaw,
        ?string $trim, ?string $variant, ?string $package, int $confidence
    ): void {
        $suggestedAction = $trim ? 'set_trim' : ($variant ? 'set_variant' : ($package ? 'set_package' : null));
        $suggestedValue  = $trim ?? $variant ?? $package;

        TaxonomyAnomalyQueue::updateOrCreate(
            [
                'source'       => $source,
                'make'         => $make ?: null,
                'unknown_tail' => $modelRaw,
            ],
            [
                'sample_model_raw'     => $badgeRaw ?: $modelRaw,
                'suggested_action'     => $suggestedAction,
                'suggested_value'      => $suggestedValue,
                'suggestion_confidence' => $confidence / 100,
                'status'               => 'ai_reviewed',
                'reason'               => 'ai_classify_patterns',
                'seen_count'           => 1,
                'last_seen_at'         => now(),
            ]
        );
    }

    private function nullOrString(mixed $value): ?string
    {
        if ($value === null || $value === 'null' || $value === '') {
            return null;
        }
        return trim((string) $value);
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a Korean car listing parser. Your job is structured extraction — NOT free reasoning about cars.

INPUT: { make, model_raw, badge_raw } from Korean car marketplace Encar.

OUTPUT (JSON only, no markdown):
{
  "model_clean": string,
  "generation": string|null,
  "trim": string|null,
  "variant": string|null,
  "package": string|null,
  "body_type": string|null,
  "confidence": integer 0-100,
  "notes": string,
  "debug": { "used_knowledge_model_clean": bool, "used_knowledge_body_type": bool }
}

━━━ LAYER 1: STRICT STRING EXTRACTION (no inference allowed) ━━━

generation → extract ONLY if an explicit chassis/generation code is present in the string:
  • Korean gen codes: GN7, DN8, CN7, NX4, RG3, IG, LF, YF, HG, TL, UM, QP, PE
  • German chassis: W213, W222, G30, F10, F30, C257, R231
  • Token in parentheses like (G30), or standalone before/after model name
  • "3세대", "4세대" → set as "3세대" etc.
  • If NOT explicitly present → null. NEVER guess.

trim → extract ONLY if an explicit grade name is present:
  • Korean grades: 캘리그래피, 프레스티지, 노블레스, 인스퍼레이션, 익스클루시브, 모던, 르블랑, 시그니처, 퍼스트
  • English grades: Prestige, Inspiration, Premium, Exclusive, Modern, HIGH, Luxury, Elegance
  • Brand grades: Inscription, R-Design, Polestar, M Sport, AMG Line, S-Line, avantgarde, 아방가르드
  • If NOT in string → null.

variant → extract ONLY if a short standalone sub-model code is present:
  • Valid: S, SD, N, AMG, RS, M, JCW, GT, GTS, 4S, e-tron, R-Line, T-Roc
  • NOT engine displacement (2.0, 3.5), NOT fuel tokens (HEV, EV, GDI)
  • If NOT in string → null.

package → extract ONLY if a package/option name is explicitly present (패키지, 팩, Package suffix).

━━━ LAYER 2: CONTROLLED KNOWLEDGE (only 2 fields) ━━━

model_clean → USE knowledge to:
  1. Strip noise: 더 뉴, 올 뉴, 뉴, (generation codes), engine size tokens, fuel tokens, drivetrain tokens
  2. Map to canonical OEM model family using this dictionary:
  Hyundai: 아반떼(CN7/AD/MD)→아반떼, 쏘나타(DN8/LF/YF)→쏘나타, 그랜저(GN7/IG/HG)→그랜저,
           투싼(NX4/TL)→투싼, 싼타페(TM/CM)→싼타페, 코나→코나, 아이오닉→아이오닉, 팰리세이드→팰리세이드
  Kia: K5(DL3/JF)→K5, K7/K8→K8, 스포티지(NQ5/QL)→스포티지, 쏘렌토(MQ4/UM)→쏘렌토,
       카니발(KA4)→카니발, EV6→EV6, 모닝→모닝, 레이→레이
  Genesis: GV80(RG3)→GV80, GV70(JK1)→GV70, G80(RG3/DH)→G80, G90→G90, G70→G70
  BMW: 3시리즈/3 Series→3시리즈, 5시리즈→5시리즈, 7시리즈→7시리즈, X3→X3, X5→X5
  Mercedes: E클래스/E-Class/E클 (W213/W212)→E클래스, S클래스→S클래스, C클래스→C클래스, GLE→GLE, GLC→GLC
  Set debug.used_knowledge_model_clean=true if you used knowledge (not just string stripping).

body_type → USE knowledge to classify make+model_clean:
  sedan: 아반떼, 쏘나타, 그랜저, K5, K7, K8, G70, G80, G90, 3시리즈, 5시리즈, 7시리즈, E클래스, S클래스, C클래스
  suv: 투싼, 싼타페, 코나, 팰리세이드, GV70, GV80, 스포티지, 쏘렌토, X3, X5, GLE, GLC, 셀토스, 베뉴
  hatchback: 아이오닉5(if not specified), 아이오닉6, i30, 폴로, 골프, 해치백
  van/minivan: 카니발, 스타리아, 쏠라티, 그랜드 스타렉스
  pickup: 포터, 봉고
  coupe: 쿠페 variants, 2시리즈 쿠페, C클래스 쿠페
  Set debug.used_knowledge_body_type=true.

━━━ LAYER 3: STRICT NULLS ━━━
• fuel → DO NOT output (handled by separate system)
• drive_type → DO NOT output (handled by separate system)
• engine_volume → DO NOT output (handled by separate system)
• confidence: reflect only model_clean + trim + generation + variant accuracy
• If uncertain about ANY field → set it null, lower confidence
PROMPT;
    }
}
