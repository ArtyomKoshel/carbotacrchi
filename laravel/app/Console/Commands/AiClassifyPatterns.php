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
        {--confidence=50  : Auto-create rule threshold (0-100)}
        {--dry-run        : Show results without saving}
        {--sleep=4        : Seconds to sleep between API calls (default 4 to stay under TPM limit)}';

    protected $description = 'AI-classify unique (make, model_kr_raw, badge_kr) patterns → auto-create rules or queue for review';

    private string $apiUrl;
    private string $aiModel;
    private array  $apiKeys = [];
    private int    $keyIndex = 0;
    private array  $bannedKeys = [];

    public function handle(): int
    {
        $this->apiUrl  = config('ai.api_url', '');
        $this->aiModel = config('ai.model', '');

        // Build key pool: AI_API_KEYS (comma-separated) + AI_API_KEY as fallback
        $pool = array_values(array_filter(config('ai.api_keys', [])));
        $single = config('ai.api_key', '');
        if ($single !== '' && !in_array($single, $pool, true)) {
            $pool[] = $single;
        }
        $this->apiKeys = $pool;

        if (empty($this->apiKeys)) {
            $this->error('No AI API keys configured (AI_API_KEY or AI_API_KEYS).');
            return 1;
        }

        $this->line("Key pool: " . count($this->apiKeys) . " key(s)");

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
            ->whereNotExists(function ($sub) use ($source) {
                $sub->select(DB::raw(1))
                    ->from('taxonomy_rules')
                    ->where('taxonomy_rules.source', $source)
                    ->whereRaw("taxonomy_rules.model_contains = COALESCE(
                        JSON_UNQUOTE(JSON_EXTRACT(lots.raw_data, '$.model_kr_raw')),
                        JSON_UNQUOTE(JSON_EXTRACT(lots.raw_data, '$.model_kr')),
                        lots.model
                    )")
                    ->where('taxonomy_rules.notes', 'LIKE', 'ai_classify_patterns%');
            })
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

            // PHP-side deterministic completeness score (additive from 0)
            // Reflects only what was actually extracted — not dictionary knowledge alone
            $completeness = 0;
            if ($modelClean !== '') $completeness += 40;           // model identified
            if ($generationCode !== null) $completeness += 20;     // chassis code explicit
            elseif ($generationLabel !== null) $completeness += 15; // generational label explicit
            if ($trim !== null) $completeness += 15;               // trim grade explicit
            if ($variant !== null) $completeness += 10;            // variant code explicit
            if ($bodyType !== null) $completeness += 10;           // body from dictionary
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

        $activeKeys = array_values(array_diff($this->apiKeys, $this->bannedKeys));
        if (empty($activeKeys)) {
            Log::error('[AiClassifyPatterns] No active API keys remaining.');
            return null;
        }
        $currentKey = $activeKeys[$this->keyIndex % count($activeKeys)];

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $currentKey,
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

            // 400 organization_restricted → blacklist key permanently and rotate
            if ($response->status() === 400 && str_contains($response->body(), 'organization_restricted')) {
                $this->bannedKeys[] = $currentKey;
                $this->keyIndex++;
                $activeKeys = array_diff($this->apiKeys, $this->bannedKeys);
                if (empty($activeKeys)) {
                    $this->error('All API keys are banned/restricted.');
                    return null;
                }
                $this->warn("    🚫 Key banned (org restricted) → rotating to next key (" . count($activeKeys) . " remaining)");
                return $this->classifyPattern($make, $modelRaw, $badgeRaw, $attempt + 1);
            }

            if ($response->status() === 429) {
                $activeKeys = array_diff($this->apiKeys, $this->bannedKeys);
                $keyCount = count($activeKeys);
                if ($keyCount === 0) { return null; }
                $triedKeys = $attempt % $keyCount;

                // Rotate to next key first
                $this->keyIndex++;

                if ($triedKeys < $keyCount - 1) {
                    // Still have untried keys — rotate without waiting
                    $this->line("    🔄 Key #{$triedKeys} rate limited → rotating to key #" . ($triedKeys + 1));
                    return $this->classifyPattern($make, $modelRaw, $badgeRaw, $attempt + 1);
                }

                if ($attempt < $keyCount * 2) {
                    // All keys tried once — wait and retry from next key
                    $waitSec = $this->parseRetryAfter($response->body());
                    $this->line("    ⏳ All keys rate limited, waiting {$waitSec}s...");
                    sleep($waitSec);
                    return $this->classifyPattern($make, $modelRaw, $badgeRaw, $attempt + 1);
                }

                Log::warning('[AiClassifyPatterns] All keys exhausted: ' . $response->body());
                return null;
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
        $makeVal = $make ?: null;

        // Each action uses a token-level model_contains key (reusable across listings)
        // instead of the full raw string (snapshot = non-reusable).
        //
        // generation code  → "$modelClean $generationCode"  e.g. "그랜저 IG"
        // generation label → "$modelClean $generationLabel" e.g. "쏘렌토 4세대"
        // trim / package   → "$trim" / "$package"           token with make scope
        // body_type        → "$modelClean"                  model→body canonical lookup
        // set_variant      → DISABLED (engine grades like 300d misclassified as variant)

        $actions = [];

        if ($generationCode !== null && $generationCode !== '') {
            $actions[] = [
                'model_contains' => trim($modelClean . ' ' . $generationCode),
                'action'         => 'set_generation',
                'action_value'   => $generationCode,
            ];
        }
        if ($generationLabel !== null && $generationLabel !== '') {
            $actions[] = [
                'model_contains' => trim($modelClean . ' ' . $generationLabel),
                'action'         => 'set_generation',
                'action_value'   => $generationLabel,
            ];
        }
        if ($trim !== null && $trim !== '') {
            $actions[] = [
                'model_contains' => $trim,
                'action'         => 'set_trim',
                'action_value'   => $trim,
            ];
        }
        // Variant: strict whitelist — only true performance submodels
        $variantWhitelist = ['AMG', 'RS', 'M', 'N', 'JCW', 'STI', 'Type R', 'GR', 'GTI', 'R', '4S', 'GTS', 'Turbo S'];
        if ($variant !== null && $variant !== '' && in_array($variant, $variantWhitelist, true)) {
            $actions[] = [
                'model_contains' => $variant,
                'action'         => 'set_variant',
                'action_value'   => $variant,
            ];
        }
        if ($package !== null && $package !== '') {
            $actions[] = [
                'model_contains' => $package,
                'action'         => 'set_package',
                'action_value'   => $package,
            ];
        }
        if ($bodyType !== null && $bodyType !== '') {
            $actions[] = [
                'model_contains' => $modelClean,
                'action'         => 'set_body_type',
                'action_value'   => $bodyType,
            ];
        }
        // NOTE: fuel/drive_type/engine_volume are handled by deterministic rules (encar_rules_seed.sql)
        // NOTE: set_variant disabled — engine grades (300d, 45 TFSI, 2.5T) are not variants

        foreach ($actions as $actionData) {
            $exists = TaxonomyRule::query()
                ->where('source', $source)
                ->where('make', $makeVal)
                ->where('model_contains', $actionData['model_contains'])
                ->where('action', $actionData['action'])
                ->where('is_active', true)
                ->exists();

            if (!$exists) {
                TaxonomyRule::create([
                    'source'         => $source,
                    'make'           => $makeVal,
                    'model_contains' => $actionData['model_contains'],
                    'action'         => $actionData['action'],
                    'action_value'   => $actionData['action_value'],
                    'priority'       => 85,
                    'is_active'      => true,
                    'notes'          => $notes,
                ]);
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
Extract grade name if EXPLICITLY present. NEVER silently drop — if you found it, always output it.
  Korean: 캘리그래피, 프레스티지, 노블레스, 인스퍼레이션, 익스클루시브, 모던, 르블랑, 시그니처, 퍼스트, 프리미엄, 럭셔리
  English: Prestige, Inspiration, Premium, Exclusive, Modern, HIGH, Luxury, Elegance, Signature, LTZ, LX, LE, RE, SE, PE
  Brand: Inscription, R-Design, M Sport, AMG Line, S-Line, avantgarde, 아방가르드, F Sport, Polestar, ACTIV, RedLine, 레드라인
  Compound trim: if you see "M 스포츠 플러스" → trim="M 스포츠", package="플러스"
  CRITICAL: if trim token is found but you are unsure → still output it, just lower parse_confidence
  NOT present → null.

━━━ FIELD 4: variant (strict whitelist only) ━━━
Extract ONLY if one of these EXACT performance submodel tokens is present in the string.
This is a per-brand whitelist — same token means DIFFERENT things for different brands:
  Universal variants: AMG, RS, M, N, JCW, STI, Type R, GR, GTI, R, 4S, GTS, Turbo S
  Brand-specific:
    Hyundai/Kia N → variant (performance submodel)
    Audi RS → variant (performance submodel)
    Mercedes AMG → variant (e.g. C63 AMG)
    BMW M → variant (M3, M5 — NOT "M Sport" which is trim)
    Chevrolet RS → trim (appearance package, NOT variant)
    Chevrolet ACTIV → trim
    Kia/Hyundai GT → variant only if it is the full submodel name (e.g. "i30 N", "Stinger GT")
  Engine displacement tokens (300d, 520i, 45 TFSI, 2.0T) → NOT variant, ignore
  NOT in whitelist → null.

━━━ FIELD 5: package (strict extraction) ━━━
Only if 패키지, 팩, 플러스, Package suffix explicitly present, OR compound trim split (see FIELD 3).
  특별 packages: 하이리무진 → package="하이리무진", 9인승 → package="9인승"

━━━ FIELD 6: body_type (knowledge, conditional) ━━━
ONLY classify if model_clean confidence ≥ 80. MUST be exactly ONE value from the enum below.
NEVER output slash-separated values like "hatchback/crossover" or "van/minivan".
If model_clean is ambiguous → body_type must be null.
  sedan:      아반떼, 쏘나타, 그랜저, K5, K8, G70, G80, G90, 3시리즈, 5시리즈, 7시리즈, E클래스, S클래스, C클래스, A4, A6, A8, ES, LS, S90, 말리부, 임팔라
  suv:        투싼, 싼타페, 코나, 팰리세이드, GV70, GV80, 스포티지, 쏘렌토, 셀토스, X3, X5, X6, X7, GLE, GLC, GLS, Q5, Q7, Q8, RX, NX, XC60, XC90, 토레스, 티볼리, 렉스턴, 코란도, QM6, 트래버스, 트랙스, 트랙스 크로스오버
  hatchback:  i30, 골프, 폴로, 피에스타, 캐스퍼
  crossover:  아이오닉5, 아이오닉6, EV6, EV9, 코나 일렉트릭, GV60
  minivan:    카니발, 그랜드 스타렉스
  van:        스타리아, 쏠라티, 레이, 포터 캡밴
  pickup:     포터, 봉고
  coupe:      2시리즈, 4시리즈, C클래스 쿠페, CLA, CLS, Z4
  convertible: E클래스 카브리올레, C클래스 카브리올레

━━━ STRICT NULLS ━━━
• fuel → DO NOT output
• drive_type → DO NOT output
• engine_volume → DO NOT output
• When in doubt about trim → output it anyway with lower confidence, do NOT drop silently
PROMPT;
    }
}
