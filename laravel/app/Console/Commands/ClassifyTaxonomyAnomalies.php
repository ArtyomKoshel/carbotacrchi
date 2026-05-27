<?php

namespace App\Console\Commands;

use App\Models\TaxonomyAnomalyQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClassifyTaxonomyAnomalies extends Command
{
    protected $signature = 'taxonomy:classify-anomalies
        {--source=encar : Source filter}
        {--batch=20 : Items per AI call}
        {--limit=200 : Max items to process}
        {--dry-run : Show AI responses without saving}';

    protected $description = 'Use AI to classify unknown taxonomy tails (trim/package/variant/model/noise)';

    private string $apiKey;
    private string $apiUrl;
    private string $model;

    public function handle(): int
    {
        $this->apiKey = config('ai.api_key', '');
        $this->apiUrl = config('ai.api_url', '');
        $this->model  = config('ai.model', '');

        if (empty($this->apiKey)) {
            $this->error('AI_API_KEY not configured.');
            return 1;
        }

        $source   = $this->option('source');
        $batchSize = (int) $this->option('batch');
        $limit    = (int) $this->option('limit');
        $dryRun   = (bool) $this->option('dry-run');

        $items = TaxonomyAnomalyQueue::query()
            ->where('source', $source)
            ->where('status', 'new')
            ->orderByDesc('seen_count')
            ->limit($limit)
            ->get();

        if ($items->isEmpty()) {
            $this->info('No new anomalies to classify.');
            return 0;
        }

        $this->info("Classifying {$items->count()} anomalies in batches of {$batchSize}...");

        $batches = $items->chunk($batchSize);
        $updated = 0;
        $failed  = 0;

        foreach ($batches as $batch) {
            $results = $this->classifyBatch($batch->all());
            if ($results === null) {
                $failed += $batch->count();
                continue;
            }

            foreach ($batch as $item) {
                $key = (string) $item->id;
                $res = $results[$key] ?? null;
                if ($res === null) {
                    continue;
                }

                $type       = $res['type'] ?? null;
                $value      = $res['value'] ?? $item->unknown_tail;
                $confidence = (float) ($res['confidence'] ?? 0.5);

                $action = match ($type) {
                    'trim'     => 'set_trim',
                    'package'  => 'set_package',
                    'variant'  => 'set_variant',
                    'noise'    => 'strip_tail',
                    default    => null,
                };

                $line = "  [{$item->id}] make={$item->make} tail=\"{$item->unknown_tail}\" "
                    . "raw=\"{$item->sample_model_raw}\" → type={$type} action={$action} conf={$confidence}";
                $this->line($line);

                if (!$dryRun && $action !== null) {
                    $item->update([
                        'suggested_action'     => $action,
                        'suggested_value'      => $value,
                        'suggestion_confidence' => $confidence,
                        'status'               => 'ai_reviewed',
                    ]);
                    $updated++;
                }
            }
        }

        $this->info("Done. Updated: {$updated}, AI errors: {$failed}");
        return 0;
    }

    /** @param TaxonomyAnomalyQueue[] $items */
    private function classifyBatch(array $items): ?array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                'id'       => (string) $item->id,
                'make'     => $item->make,
                'model'    => $item->sample_model_raw,
                'tail'     => $item->unknown_tail,
            ];
        }

        $prompt = $this->buildPrompt();
        $input  = json_encode($rows, JSON_UNESCAPED_UNICODE);

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->post($this->apiUrl, [
                    'model'           => $this->model,
                    'max_tokens'      => 1500,
                    'temperature'     => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => [
                        ['role' => 'system', 'content' => $prompt],
                        ['role' => 'user',   'content' => $input],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('[TaxonomyClassify] API error: ' . $response->status());
                return null;
            }

            $content = $response->json('choices.0.message.content', '');
            $json    = json_decode($content, true);
            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::error('[TaxonomyClassify] ' . $e->getMessage());
            return null;
        }
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
You are an expert in Korean car marketplace (Encar) taxonomy. Given a list of car listings where the "tail" token at the end of the model string could not be automatically classified, determine what each token represents.

Input: JSON array with fields: id, make, model (full raw string from Encar), tail (the unclassified token).

Use your knowledge of Korean and international car models, trim levels, option packages, engine variants, and generations to classify each "tail":
- "trim": A grade/trim level (프레스티지, 노블레스, 스포츠, AMG, N라인, 모던, 에디션 variants, GT-Line, etc.)
- "package": An option package (패키지, 팩, 래더패키지, AMG패키지, 디자인패키지, etc.)
- "variant": Engine/performance code that is part of the model string (320d, S500L, 55 TFSI, 2.0T, etc.)
- "body_style": Body type (쿠페, 해치백, 왜건, 4도어, etc.)
- "model_suffix": A sub-model name that belongs in the model field (뉴 라이즈, 더 볼드, 마이스터, 플래티넘 as sub-model)
- "noise": Irrelevant token, should be removed

Also return "value": the clean classified value (strip drive tokens like 2WD/4WD if mixed in).
Return "reason": one short sentence explaining your decision.

Return JSON object: keys are "id" values, values are {"type":"...","value":"...","confidence":0.0-1.0,"reason":"..."}
PROMPT;
    }
}
