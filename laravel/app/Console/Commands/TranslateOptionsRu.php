<?php

namespace App\Console\Commands;

use App\Models\EncarOptionCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI-именование и перевод опций в encar_option_catalog.
 *
 * Два режима работы:
 *  1. name_kr IS NULL  → AI генерирует name_kr (корейское название) + name_ru + name_en
 *  2. name_kr заполнен → AI переводит name_kr → name_ru + name_en
 *
 * Использует тот же Groq API что taxonomy:classify-anomalies.
 *
 * Запуск:
 *   php artisan options:name-by-ai          # только коды без name_kr
 *   php artisan options:name-by-ai --all    # перезаписать все (включая переведённые)
 *   php artisan options:name-by-ai --dry-run
 */
class TranslateOptionsRu extends Command
{
    protected $signature = 'options:name-by-ai
        {--batch=20  : Items per AI call}
        {--all       : Process all records, overwrite existing names}
        {--dry-run   : Show AI output without saving}';

    protected $description = 'AI-generate name_kr / name_ru / name_en for encar_option_catalog entries';

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
        $all       = (bool) $this->option('all');
        $dryRun    = (bool) $this->option('dry-run');

        $query = EncarOptionCatalog::query()->orderBy('code');

        if (!$all) {
            // Process only records that are missing at least name_ru
            $query->where(function ($q) {
                $q->whereNull('name_ru')->orWhereNull('name_kr');
            });
        }

        $items = $query->get(['code', 'name_kr', 'name_en', 'name_ru']);

        if ($items->isEmpty()) {
            $this->info('Nothing to process. Use --all to re-generate all names.');
            return 0;
        }

        $withKr    = $items->whereNotNull('name_kr')->count();
        $withoutKr = $items->whereNull('name_kr')->count();
        $this->info("Processing {$items->count()} options ({$withKr} have name_kr, {$withoutKr} need AI naming)...");

        $batches = $items->chunk($batchSize);
        $saved   = 0;
        $failed  = 0;

        foreach ($batches as $batch) {
            $translations = $this->processBatch($batch->all());

            if ($translations === null) {
                $this->warn('  AI call failed for a batch, skipping.');
                $failed += $batch->count();
                continue;
            }

            foreach ($batch as $option) {
                $t = $translations[$option->code] ?? null;
                if (!$t) {
                    $this->warn("  [{$option->code}] No response from AI");
                    continue;
                }

                $nameKr = $t['kr'] ?? null;
                $nameRu = $t['ru'] ?? null;
                $nameEn = $t['en'] ?? null;

                $display = $nameKr ?? $option->name_kr ?? '?';
                $this->line("  [{$option->code}] {$display} → ru={$nameRu} en={$nameEn}");

                if (!$dryRun) {
                    $update = [];
                    if ($nameKr && !$option->name_kr) {
                        $update['name_kr'] = $nameKr;
                    }
                    if ($nameRu) {
                        $update['name_ru'] = $nameRu;
                    }
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
            $this->info("Done. Updated: {$saved}, AI errors (batches): {$failed}.");
            $this->info('Redis cache flushed.');
        } else {
            $this->info('[dry-run] No changes saved.');
        }

        return 0;
    }

    /**
     * @param  EncarOptionCatalog[] $items
     * @return array<string, array{kr:?string, ru:?string, en:?string}>|null  keyed by code
     */
    private function processBatch(array $items): ?array
    {
        $rows = [];
        foreach ($items as $opt) {
            $rows[] = [
                'code'    => $opt->code,
                'name_kr' => $opt->name_kr, // null if not yet known
                'name_en' => $opt->name_en,
            ];
        }

        $input  = json_encode($rows, JSON_UNESCAPED_UNICODE);
        $prompt = $this->buildPrompt();

        try {
            $response = Http::timeout(45)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->post($this->apiUrl, [
                    'model'           => $this->model,
                    'max_tokens'      => 2000,
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
You are an automotive expert specializing in the Korean car marketplace Encar (encar.com).

Input: JSON array of option entries with fields:
- "code": Encar option code (e.g. "010", "022")
- "name_kr": Official Korean name from Encar (e.g. "선루프", "후방 카메라"). May be null for rare codes.
- "name_en": Existing English translation if available, or null

Your tasks:
1. Translate "name_kr" to Russian → "ru" (concise, 1-5 words, professional automotive term)
2. Translate "name_kr" to English → "en" (concise, 1-4 words; use existing "name_en" as hint if provided)
3. If "name_kr" is null → infer Korean name from the code and provide all three translations

Translation rules:
- Keep names SHORT — drivers read these in a list, not an article
  Good: "Подогрев сидений", "Камера заднего вида", "Адаптивный круиз"
  Bad: "Функция подогрева передних и задних сидений"
- Preserve international acronyms in all languages: ABS, TCS, ESC, TPMS, HUD, EPB, ECM, LED, HID, AV
- Korean → Russian common mappings:
  선루프→Люк, 파워 전동 트렁크→Электропривод багажника,
  전동접이 사이드 미러→Складные зеркала, 알루미늄 휠→Литые диски,
  루프랙→Рейлинги, 열선 스티어링 휠→Подогрев руля,
  전동 조절 스티어링 휠→Электрорегулировка руля, 패들 시프트→Подрулевые лепестки,
  스티어링 휠 리모컨→Кнопки на руле, ECM 룸미러→Автозатемнение зеркала,
  하이패스→Hi-Pass (транспондер), 파워 도어록→Центральный замок,
  파워 스티어링 휠→Усилитель руля, 파워 윈도우→Электростёкла,
  에어백(운전석)→Подушка водителя, 에어백(동승석)→Подушка пассажира,
  에어백(사이드)→Боковые подушки, 에어백(커튼)→Шторки безопасности,
  브레이크 잠김 방지(ABS)→ABS, 미끄럼 방지(TCS)→TCS, 차체자세 제어장치(ESC)→ESC,
  타이어 공기압센서(TPMS)→TPMS, 차선이탈 경보 시스템(LDWS)→Контроль полосы (LDWS),
  전자제어 서스펜션(ECS)→Активная подвеска (ECS),
  주차감지센서(전방)→Парктроник передний, 주차감지센서(후방)→Парктроник задний,
  후측방 경보 시스템→Контроль мёртвых зон, 후방 카메라→Камера заднего вида,
  360도 어라운드 뷰→Камера 360°, 크루즈 컨트롤(일반)→Круиз-контроль,
  크루즈 컨트롤(어댑티브)→Адаптивный круиз (ACC),
  헤드업 디스플레이(HUD)→HUD (проекция на стекло),
  전자식 주차브레이크(EPB)→Электропарковочный тормоз (EPB),
  자동 에어컨→Климат-контроль, 스마트키→Смарт-ключ,
  무선도어 잠금장치→Дистанционный замок, 레인센서→Датчик дождя,
  오토 라이트→Автосвет, 커튼/블라인드(뒷좌석)→Шторки задних дверей,
  커튼/블라인드(후방)→Шторка заднего стекла, 내비게이션→Навигация,
  앞좌석 AV 모니터→Монитор (передний), 뒷좌석 AV 모니터→Монитор (задний),
  블루투스→Bluetooth, CD 플레이어→CD-проигрыватель,
  USB 단자→USB, AUX 단자→AUX,
  가죽시트→Кожаный салон, 전동시트(운전석)→Электросиденье водителя,
  전동시트(동승석)→Электросиденье пассажира, 전동시트(뒷좌석)→Электросиденья задние,
  열선시트(앞좌석)→Подогрев сидений (перед), 열선시트(뒷좌석)→Подогрев сидений (зад),
  메모리 시트(운전석)→Память сиденья, 메모리 시트(동승석)→Память сиденья пассажира,
  통풍시트(운전석)→Вентиляция сидений (перед), 통풍시트(동승석)→Вентиляция сидений (пасс),
  통풍시트(뒷좌석)→Вентиляция сидений (зад), 마사지 시트→Массаж сидений,
  헤드램프(HID)→Фары HID, 헤드램프(LED)→Фары LED,
  고스트 도어 클로징→Мягкое закрытие дверей

Return JSON object: keys = code values, values = {"kr":"...","ru":"...","en":"..."}
Return ONLY the JSON object, no explanation.
PROMPT;
    }
}
