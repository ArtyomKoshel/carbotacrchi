<?php

namespace App\Console\Commands;

use App\Models\EncarOptionCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Batch AI-перевод name_kr → name_ru для записей encar_option_catalog.
 *
 * Запуск:
 *   php artisan options:translate-ru
 *   php artisan options:translate-ru --batch=30 --dry-run
 *   php artisan options:translate-ru --force           # перезаписать уже переведённые
 *
 * Используется тот же Groq API что и taxonomy:classify-anomalies.
 */
class TranslateOptionsRu extends Command
{
    protected $signature = 'options:translate-ru
        {--batch=20  : Items per AI call}
        {--force     : Re-translate even if name_ru is already set}
        {--dry-run   : Show AI output without saving}';

    protected $description = 'AI-translate option name_kr → name_ru (and name_en if missing) in encar_option_catalog';

    private string $apiKey;
    private string $apiUrl;
    private string $model;

    public function handle(): int
    {
        $this->apiKey = config('ai.api_key', '');
        $this->apiUrl = config('ai.api_url', '');
        $this->model  = config('ai.model', '');

        if (empty($this->apiKey)) {
            $this->error('AI_API_KEY not configured. Set it in .env');
            return 1;
        }

        $batchSize = (int) $this->option('batch');
        $force     = (bool) $this->option('force');
        $dryRun    = (bool) $this->option('dry-run');

        $query = EncarOptionCatalog::query()->orderBy('sort_order');

        if (!$force) {
            $query->whereNull('name_ru');
        }

        $items = $query->get(['code', 'name_kr', 'name_en', 'name_ru']);

        if ($items->isEmpty()) {
            $this->info('Nothing to translate. Use --force to re-translate all.');
            return 0;
        }

        $this->info("Translating {$items->count()} options in batches of {$batchSize}...");

        $batches  = $items->chunk($batchSize);
        $saved    = 0;
        $failed   = 0;

        foreach ($batches as $batch) {
            $translations = $this->translateBatch($batch->all());

            if ($translations === null) {
                $this->warn('AI call failed for a batch, skipping.');
                $failed += $batch->count();
                continue;
            }

            foreach ($batch as $option) {
                $t = $translations[$option->code] ?? null;
                if (!$t) {
                    $this->warn("  No translation returned for code={$option->code}");
                    continue;
                }

                $nameRu = $t['ru'] ?? null;
                $nameEn = $t['en'] ?? null;

                $this->line("  [{$option->code}] {$option->name_kr} → ru={$nameRu} en={$nameEn}");

                if (!$dryRun) {
                    $update = [];
                    if ($nameRu) {
                        $update['name_ru'] = $nameRu;
                    }
                    // Only fill name_en if it's not already set
                    if ($nameEn && !$option->name_en) {
                        $update['name_en'] = $nameEn;
                    }

                    if (!empty($update)) {
                        $option->update($update);
                        $saved++;
                    }
                }
            }
        }

        if (!$dryRun) {
            EncarOptionCatalog::flushCache();
            $this->info("Done. Saved: {$saved}, AI errors: {$failed}.");
            $this->info('Redis cache flushed.');
        } else {
            $this->info('[dry-run] No changes saved.');
        }

        return 0;
    }

    /**
     * @param  EncarOptionCatalog[] $items
     * @return array<string, array{ru: ?string, en: ?string}>|null  keyed by option code
     */
    private function translateBatch(array $items): ?array
    {
        $rows = [];
        foreach ($items as $opt) {
            $rows[] = [
                'code'    => $opt->code,
                'name_kr' => $opt->name_kr,
                'name_en' => $opt->name_en, // pass existing EN so AI can use it as hint
            ];
        }

        $input  = json_encode($rows, JSON_UNESCAPED_UNICODE);
        $prompt = $this->buildPrompt();

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
                Log::warning('[TranslateOptionsRu] API error: ' . $response->status());
                return null;
            }

            $content = $response->json('choices.0.message.content', '');
            $json    = json_decode($content, true);

            return is_array($json) ? $json : null;

        } catch (\Throwable $e) {
            Log::error('[TranslateOptionsRu] ' . $e->getMessage());
            return null;
        }
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
You are an expert translator for Korean automotive terminology. Your task is to translate car option/feature names from Korean to Russian and English.

Input: JSON array of option objects, each with:
- "code": option code (e.g. "001")
- "name_kr": Korean name (e.g. "선루프")
- "name_en": existing English translation if available (may be null)

Return a JSON object where:
- keys are "code" values (e.g. "001")
- values are {"ru": "Russian name", "en": "English name"}

Translation rules:
- "ru": concise Russian name for a car feature (1-4 words), use automotive industry terms
- "en": concise English name (1-4 words); if "name_en" input is already good, use it as-is
- Keep names short: "선루프" → {"ru": "Люк", "en": "Sunroof"}
- Preserve international abbreviations: "ABS", "ESP", "LED", "HUD", "ACC" etc.
- Common mappings: 선루프→Люк, 후방카메라→Камера заднего вида, 네비게이션→Навигация,
  열선시트→Подогрев сидений, 가죽시트→Кожаный салон, 전동시트→Электросиденья,
  통풍시트→Вентиляция сидений, 스마트키→Смарт-ключ, 크루즈컨트롤→Круиз-контроль,
  어라운드뷰→Камера 360°, 헤드업디스플레이→HUD, 차선이탈경보→Контроль полосы,
  자동주차→Автопарковка, 블라인드스팟→Контроль мёртвых зон

Return ONLY the JSON object, no explanation.
PROMPT;
    }
}
