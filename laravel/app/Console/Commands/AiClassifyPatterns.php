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

            $modelClean    = trim((string) ($result['model_clean'] ?? ''));
            $generationCode = $this->nullOrString($result['generation_code'] ?? null);
            $generationLabel = $this->nullOrString($result['generation_label'] ?? null);
            $generation    = $generationCode ?? $generationLabel;
            $trim          = $this->nullOrString($result['trim'] ?? null);
            $variant       = $this->nullOrString($result['variant'] ?? null);
            $package       = $this->nullOrString($result['package'] ?? null);
            $bodyType      = $this->nullOrString($result['body_type'] ?? null);
            $parseConf     = (int) ($result['parse_confidence'] ?? 0);
            $notes         = trim((string) ($result['notes'] ?? ''));

            // PHP-side deterministic data_completeness score
            $completeness = 60;
            if ($modelClean !== '') $completeness += 20;
            if ($generationCode !== null) $completeness += 10;
            if ($bodyType !== null) $completeness += 10;
            if ($trim !== null) $completeness += 10;
            if ($variant !== null) $completeness += 5;
            $completeness = min(95, $completeness);

            $confidence = $completeness;

            $this->line("    → parse={$parseConf}% compl={$completeness}% model_clean=\"{$modelClean}\" gen_code=\"{$generationCode}\" gen_label=\"{$generationLabel}\" trim=\"{$trim}\" body=\"{$bodyType}\"");
            if ($notes) $this->line("    → notes: {$notes}");

            if ($confidence >= $threshold) {
                if (!$dryRun) {
                    $created = $this->createRules($source, $make, $modelRaw, $modelClean, $generationCode, $generationLabel, $trim, $variant, $package, $bodyType, $confidence);
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
        // "3m33.408s" format
        if (preg_match('/try again in (\d+)m(\d+\.?\d*)s/', $body, $m)) {
            return (int)$m[1] * 60 + (int) ceil((float) $m[2]) + 1;
        }
        // "1.565s" format
        if (preg_match('/try again in (\d+\.?\d*)s/', $body, $m)) {
            return (int) ceil((float) $m[1]) + 1;
        }
        return 15;
    }

    private function createRules(
        string $source, string $make, string $modelRaw, string $modelClean,
        ?string $generationCode, ?string $generationLabel, ?string $trim, ?string $variant,
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
        if ($generationCode !== null && $generationCode !== '') {
            $actions[] = ['action' => 'set_generation', 'action_value' => $generationCode];
        } elseif ($generationLabel !== null && $generationLabel !== '') {
            $actions[] = ['action' => 'set_generation', 'action_value' => $generationLabel];
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
You are a deterministic Korean car listing parser. Extract structured fields ONLY from what is explicitly present in the text.

INPUT: { make, model_raw, badge_raw } from Encar (Korean car marketplace).

OUTPUT (JSON only, no markdown):
{
  "model_clean": string,
  "generation_code": string|null,
  "generation_label": string|null,
  "trim": string|null,
  "variant": string|null,
  "package": string|null,
  "body_type": string|null,
  "parse_confidence": integer 0-100,
  "notes": string
}

DO NOT compute bonuses or penalties. DO NOT evaluate completeness. Just extract.
parse_confidence = your certainty about what you DID extract (not what is missing).

━━━ FIELD 1: model_clean (controlled knowledge allowed) ━━━
Step 1 — detect base model name from string.
Step 2 — detect generation token (see list below).
Step 3 — strip from model_clean: generation token, engine size (1.6/2.0/3.5), fuel tokens (가솔린/디젤/HEV/EV/LPG), drive tokens (2WD/4WD/AWD), noise (더 뉴/올 뉴/뉴).
Step 4 — map to canonical OEM name using dictionary (set model_clean_source="knowledge"):
  Hyundai: 아반떼(CN7/AD/MD), 쏘나타(DN8/LF/YF), 그랜저(GN7/IG/HG), 투싼(NX4/TL), 싼타페(TM/CM/MX5), 코나, 아이오닉5, 아이오닉6, 팰리세이드
  Kia: K5(DL3/JF), K7→K8, K8, 스포티지(NQ5/QL), 쏘렌토(MQ4/UM), 카니발(KA4), EV6, 모닝, 레이, 셀토스
  Genesis: GV80(RG3), GV70(JK1), G80(DH/RS4), G90(RS4), G70
  BMW: 3시리즈(F30/G20), 5시리즈(F10/G30), 7시리즈(F01/G11), X3(G01), X5(F15/G05), X6, X7
  Mercedes: E클래스(W213/W212), S클래스(W222/W223), C클래스(W205/W206), GLE(W167), GLC(X253), GLS
  Audi: A4, A6, A8, Q5, Q7, Q8, e-tron
  Volvo: XC60, XC90, XC40, S90, V90
  Lexus: ES, RX, NX, UX, LS, GX
If no dictionary match → use string value as-is, set model_clean_source="string".

━━━ FIELDS 2+3: generation_code and generation_label (TWO SEPARATE FIELDS) ━━━
These are different classification systems — NEVER mix them.

generation_code = OEM chassis/platform code (engineering identifier):
  Korean: GN7, DN8, CN7, NX4, RG3, IG, LF, YF, HG, TL, UM, QP, MX5, KA4, JK1, DL3, RS4, DH
  German: W213, W222, W205, G30, F10, F30, G20, C257, R231, G01, G05
  In parentheses: (G30), (DN8) → extract without parens
  If NOT present → null.

generation_label = marketing/generational label:
  Examples: 3세대, 4세대, 5세대, 신형, 구형, 1세대
  If NOT present → null.

Both can be non-null at once (e.g. 페리세이드 디젠 알파 (LX2) 2세대 → code=LX2, label=2세대).
If NOT explicitly present in string → null. NEVER infer.

━━━ FIELD 3: trim (strict string extraction only) ━━━
Extract ONLY if grade name is explicitly present in the string:
  Korean: 캘리그래피, 프레스티지, 노블레스, 인스퍼레이션, 익스클루시브, 모던, 르블랑, 시그니처, 퍼스트, 프리미엄
  English: Prestige, Inspiration, Premium, Exclusive, Modern, HIGH, Luxury, Elegance, Signature
  Brand: Inscription, R-Design, M Sport, AMG Line, S-Line, avantgarde, 아방가르드, F Sport, Polestar
  NOT present → null. Set debug.trim_found accordingly.

━━━ FIELD 4: variant (strict string extraction only) ━━━
Extract ONLY short standalone sub-model code present in string:
  Valid tokens: N, AMG, RS, M, JCW, GT, GTS, 4S, e-tron, SD, Cooper S, Turbo S
  NOT: engine displacement (2.0T, 3.5), NOT fuel tokens (HEV, EV, GDI, TDI)
  If ambiguous → null + add to ambiguity_flags.

━━━ FIELD 5: package (strict extraction) ━━━
Only if 패키지, 팩, Package suffix explicitly present.

━━━ FIELD 6: body_type (knowledge, conditional) ━━━
ONLY classify if model_clean confidence ≥ 80.
If model_clean is ambiguous → body_type must be null.
Use canonical model dictionary above. Set body_type_source="knowledge".
  sedan: 아반떼, 쏘나타, 그랜저, K5, K8, G70, G80, G90, 3시리즈, 5시리즈, 7시리즈, E클래스, S클래스, C클래스, A4, A6, A8, ES, LS, S90
  suv: 투싼, 싼타페, 코나, 팰리세이드, GV70, GV80, 스포티지, 쏘렌토, 셀토스, X3, X5, X6, X7, GLE, GLC, GLS, Q5, Q7, Q8, RX, NX, XC60, XC90
  hatchback/crossover: 아이오닉5, 아이오닉6, EV6, i30, 골프
  van/minivan: 카니발, 스타리아, 쏠라티, 그랜드 스타렉스
  pickup: 포터, 봉고
  coupe: 쿠페, 2시리즈, 4시리즈, C클래스 쿠페, CLA, CLS

━━━ STRICT NULLS ━━━
• fuel → DO NOT output (separate deterministic system handles this)
• drive_type → DO NOT output (separate deterministic system handles this)
• engine_volume → DO NOT output (separate deterministic system handles this)
• When in doubt → null + lower confidence + add to ambiguity_flags
PROMPT;
    }
}
