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
        {--dry-run        : Show results without saving}';

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

        $this->info('AI Classify Patterns');
        $this->line("Mode: " . ($dryRun ? 'DRY-RUN' : 'APPLY'));
        $this->line("Source: {$source} | Limit: {$limit} | Auto-rule confidence >= {$threshold}%");
        $this->line('');

        $query = DB::table('lots')
            ->select([
                'make',
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.model_kr_raw')) AS model_raw"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.badge_kr'))     AS badge_raw"),
                DB::raw('COUNT(*) AS cnt'),
            ])
            ->where('source', $source)
            ->whereNotNull('raw_data')
            ->whereRaw("JSON_EXTRACT(raw_data, '$.model_kr_raw') IS NOT NULL")
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
            $fuel        = $this->nullOrString($result['fuel'] ?? null);
            $driveType   = $this->nullOrString($result['drive_type'] ?? null);
            $notes       = trim((string) ($result['notes'] ?? ''));

            $this->line("    → conf={$confidence}% model_clean=\"{$modelClean}\" trim=\"{$trim}\" variant=\"{$variant}\" body=\"{$bodyType}\" notes=\"{$notes}\"");

            if ($confidence >= $threshold) {
                if (!$dryRun) {
                    $created = $this->createRules($source, $make, $modelRaw, $modelClean, $generation, $trim, $variant, $package, $bodyType, $fuel, $driveType, $confidence);
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

    private function classifyPattern(string $make, string $modelRaw, string $badgeRaw): ?array
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

    private function createRules(
        string $source, string $make, string $modelRaw, string $modelClean,
        ?string $generation, ?string $trim, ?string $variant,
        ?string $package, ?string $bodyType, ?string $fuel, ?string $driveType,
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
        if ($fuel !== null && $fuel !== '') {
            $actions[] = ['action' => 'set_fuel',       'action_value' => $fuel];
        }
        if ($driveType !== null && $driveType !== '') {
            $actions[] = ['action' => 'set_drive_type', 'action_value' => $driveType];
        }

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
You are an expert in Korean car marketplace (Encar) taxonomy normalization. Use your full knowledge of car models, trim levels, and specifications worldwide.

Given a car listing (make + raw model string + badge string from Encar), extract all available taxonomy fields.

Return JSON only (no markdown):
{
  "model_clean": "base model name only — strip out engine displacement, fuel type, drive type, trim level, variant code. Keep only the core model name.",
  "generation": "chassis or generation code if you can identify one (e.g. G30, DN8, W213, RG3, F10, 3세대) or null",
  "trim": "trim/grade level name as used by this manufacturer (e.g. 프레스티지, HIGH, Inscription, M Sport, 아방가르드) or null",
  "variant": "short sub-model performance code that is part of the model identity — like S, SD, JCW, N, GT, 4S, GTS, AMG, RS, M, e-tron. NOT engine displacement numbers. or null",
  "package": "option/equipment package name if present or null",
  "body_type": "body style you know for this model (use English lowercase: sedan, suv, hatchback, coupe, convertible, wagon, van, minivan, pickup, crossover, etc.) or null",
  "fuel": "fuel type you know for this model/variant (use English lowercase: gasoline, diesel, hybrid, electric, lpg, plugin_hybrid, hydrogen) or null",
  "drive_type": "drivetrain you know for this model/variant (fwd, rwd, awd, 4wd) or null — use context clues like AWD/4WD/xDrive/quattro/4MATIC tokens",
  "engine_volume": numeric displacement in liters as float (e.g. 2.0, 3.5) if you can identify it, or null,
  "confidence": integer 0-100 reflecting your certainty across ALL fields — lower if ambiguous,
  "notes": "one sentence explaining your reasoning or noting anything uncertain"
}

Key rules:
- model_clean is the most important field — it must be clean and correct
- variant is only SHORT codes that are part of the model name (S, JCW, N, AMG) — NOT full trim names
- trim is the equipment grade name (can be Korean or English)
- If the badge string contains generation info (e.g. "3세대"), use that for generation field
- Use your knowledge of the specific make/model to fill body_type, fuel, drive_type even if not stated in the string
PROMPT;
    }
}
