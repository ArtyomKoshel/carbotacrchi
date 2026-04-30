<?php

namespace App\Services;

use App\Models\BotFilterSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatSearchService
{
    private string $apiKey;
    private string $apiUrl;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('ai.api_key', '');
        $this->apiUrl = config('ai.api_url', 'https://api.groq.com/openai/v1/chat/completions');
        $this->model  = config('ai.model', 'llama-3.3-70b-versatile');
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @return array{query: SearchQuery, tolerantQuery: SearchQuery, description: string, toleranceNote: string}|null
     */
    public function parseAndSearch(string $text): ?array
    {
        $parsed = $this->parseQuery($text);
        if ($parsed === null) {
            return null;
        }

        $query = SearchQuery::fromArray($parsed);
        $query->limit = 50;

        $tolerantQuery = $query->withBotTolerance();
        $description   = $query->describeForChat();
        $toleranceNote = $this->buildToleranceNote($query, $tolerantQuery);

        return [
            'query'         => $query,
            'tolerantQuery' => $tolerantQuery,
            'description'   => $description,
            'toleranceNote' => $toleranceNote,
        ];
    }

    private function parseQuery(string $text): ?array
    {
        if (!$this->isAvailable()) {
            return $this->fallbackParse($text);
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->post($this->apiUrl, [
                    'model'       => $this->model,
                    'max_tokens'  => config('ai.max_tokens', 300),
                    'temperature' => config('ai.temperature', 0),
                    'messages'    => [
                        ['role' => 'system', 'content' => $this->getSystemPrompt()],
                        ['role' => 'user',   'content' => $text],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('[ChatSearch] API error: ' . $response->status() . ' ' . $response->body());
                return $this->fallbackParse($text);
            }

            $content = $response->json('choices.0.message.content', '');
            $json    = $this->extractJson($content);

            if (!$json || isset($json['error'])) {
                return null;
            }

            return $json;
        } catch (\Throwable $e) {
            Log::error('[ChatSearch] ' . $e->getMessage());
            return $this->fallbackParse($text);
        }
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $text, $m)) {
            $text = trim($m[1]);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function fallbackParse(string $text): ?array
    {
        $text   = mb_strtolower(trim($text));
        $result = [];

        $makes = json_decode(
            file_get_contents(storage_path('app/data/makes_models.json')),
            true
        ) ?: [];

        foreach ($makes as $make => $models) {
            if (mb_stripos($text, mb_strtolower($make)) !== false) {
                $result['make'] = $make;
                foreach ($models as $model) {
                    if (mb_stripos($text, mb_strtolower($model)) !== false) {
                        $result['model'] = $model;
                        break;
                    }
                }
                break;
            }
        }

        if (preg_match('/(\d{4})\s*[-–]\s*(\d{4})/', $text, $m)) {
            $result['yearFrom'] = (int) $m[1];
            $result['yearTo']   = (int) $m[2];
        } elseif (preg_match('/(?:от|from)\s*(\d{4})\s*(?:г|год)?/u', $text, $m)) {
            $result['yearFrom'] = (int) $m[1];
        } elseif (preg_match('/(?:до|to)\s*(\d{4})\s*(?:г|год)?/u', $text, $m)) {
            $result['yearTo'] = (int) $m[1];
        }

        if (preg_match('/(?:до|max|<)\s*\$?\s*(\d+)\s*\$?/u', $text, $m)) {
            $val = (int) $m[1];
            if ($val > 1000 && $val < 500000) {
                $result['priceMax'] = $val;
            }
        }

        if (preg_match('/(?:пробег|mileage).*?(?:от|from|min)\s*(\d+)/u', $text, $m)) {
            $result['mileageMin'] = (int) $m[1];
        }
        if (preg_match('/(?:пробег|mileage).*?(?:до|to|max)\s*(\d+)/u', $text, $m)) {
            $result['mileageMax'] = (int) $m[1];
        }

        $fuelMap = [
            'бензин' => 'Gasoline', 'бенз' => 'Gasoline', 'gasoline' => 'Gasoline', 'petrol' => 'Gasoline',
            'дизель' => 'Diesel', 'diesel' => 'Diesel',
            'гибрид' => 'Hybrid', 'hybrid' => 'Hybrid',
            'электр' => 'Electric', 'electric' => 'Electric',
        ];
        foreach ($fuelMap as $keyword => $value) {
            if (mb_stripos($text, $keyword) !== false) {
                $result['fuelTypes'] = [$value];
                break;
            }
        }

        $transMap = [
            'автомат' => 'Automatic', 'акпп' => 'Automatic', 'automatic' => 'Automatic',
            'механик' => 'Manual', 'мкпп' => 'Manual', 'manual' => 'Manual',
        ];
        foreach ($transMap as $keyword => $value) {
            if (mb_stripos($text, $keyword) !== false) {
                $result['transmissions'] = [$value];
                break;
            }
        }

        if (preg_match('/(\d+\.\d+)\s*(?:л|l|литр)?/u', $text, $m)) {
            $vol = (float) $m[1];
            if ($vol >= 0.5 && $vol <= 8.0) {
                $result['engineMin'] = $vol;
                $result['engineMax'] = $vol;
            }
        }

        if (preg_match('/(?:страхов|insurance).*?(?:до|max|<)\s*(\d+)/u', $text, $m)) {
            $result['insuranceCountMax'] = (int) $m[1];
        }
        if (preg_match('/(?:без\s*страхов|0\s*страхов)/u', $text)) {
            $result['insuranceCountMax'] = 0;
        }

        if (preg_match('/(\d+)\s*(?:владел|хозя|owner)/u', $text, $m)) {
            $result['ownersCountMax'] = (int) $m[1];
        }

        if (preg_match('/без\s*(?:дтп|авар|accident)/ui', $text)) {
            $result['hasAccident'] = false;
        }
        if (preg_match('/(?:с\s*дтп|были\s*авар|has.*accident)/ui', $text)) {
            $result['hasAccident'] = true;
        }

        if (preg_match('/без\s*(?:затопл|утопл|flood)/ui', $text)) {
            $result['floodHistory'] = false;
        }

        if (preg_match('/без\s*(?:залог|обременен|lien)/ui', $text)) {
            $result['lienStatuses'] = ['clean'];
        }

        $colorMap = [
            'белый' => 'White', 'белая' => 'White', 'white' => 'White',
            'черный' => 'Black', 'черная' => 'Black', 'black' => 'Black',
            'серый' => 'Gray', 'серая' => 'Gray', 'серебр' => 'Silver',
            'красный' => 'Red', 'красная' => 'Red', 'red' => 'Red',
            'синий' => 'Blue', 'синяя' => 'Blue', 'blue' => 'Blue',
        ];
        foreach ($colorMap as $keyword => $value) {
            if (mb_stripos($text, $keyword) !== false) {
                $result['colors'] = [$value];
                break;
            }
        }

        return empty($result) ? null : $result;
    }

    private function buildToleranceNote(SearchQuery $original, SearchQuery $tolerant): string
    {
        $notes = [];

        if ($original->mileageMin !== $tolerant->mileageMin || $original->mileageMax !== $tolerant->mileageMax) {
            $parts = [];
            if ($tolerant->mileageMin > 0) $parts[] = number_format($tolerant->mileageMin);
            if ($tolerant->mileageMax > 0) $parts[] = number_format($tolerant->mileageMax);
            if ($parts) $notes[] = 'пробег ' . implode('–', $parts) . ' км';
        }

        if ($original->priceMin !== $tolerant->priceMin || $original->priceMax !== $tolerant->priceMax) {
            $parts = [];
            if ($tolerant->priceMin > 0) $parts[] = '$' . number_format($tolerant->priceMin);
            if ($tolerant->priceMax > 0) $parts[] = '$' . number_format($tolerant->priceMax);
            if ($parts) $notes[] = 'цена ' . implode('–', $parts);
        }

        if ($original->engineMin !== $tolerant->engineMin || $original->engineMax !== $tolerant->engineMax) {
            $parts = [];
            if ($tolerant->engineMin > 0) $parts[] = $tolerant->engineMin . 'л';
            if ($tolerant->engineMax > 0) $parts[] = $tolerant->engineMax . 'л';
            if ($parts) $notes[] = 'двигатель ' . implode('–', $parts);
        }

        if ($original->yearFrom !== $tolerant->yearFrom || $original->yearTo !== $tolerant->yearTo) {
            $parts = [];
            if ($tolerant->yearFrom > 0) $parts[] = (string) $tolerant->yearFrom;
            if ($tolerant->yearTo > 0) $parts[] = (string) $tolerant->yearTo;
            if ($parts) $notes[] = 'год ' . implode('–', $parts);
        }

        if ($original->insuranceCountMin !== $tolerant->insuranceCountMin || $original->insuranceCountMax !== $tolerant->insuranceCountMax) {
            $parts = [];
            if ($tolerant->insuranceCountMin > 0) $parts[] = (string) $tolerant->insuranceCountMin;
            if ($tolerant->insuranceCountMax > 0 || $original->insuranceCountMax === 0) $parts[] = (string) $tolerant->insuranceCountMax;
            if ($parts) $notes[] = 'страховые ' . implode('–', $parts);
        }

        if ($original->ownersCountMin !== $tolerant->ownersCountMin || $original->ownersCountMax !== $tolerant->ownersCountMax) {
            $parts = [];
            if ($tolerant->ownersCountMin > 0) $parts[] = (string) $tolerant->ownersCountMin;
            if ($tolerant->ownersCountMax > 0 || $original->ownersCountMax === 0) $parts[] = (string) $tolerant->ownersCountMax;
            if ($parts) $notes[] = 'владельцы ' . implode('–', $parts);
        }

        return $notes ? implode(', ', $notes) : '';
    }

    private function getSystemPrompt(): string
    {
        return Cache::remember('bot_filter:system_prompt', 60, function () {
            return $this->buildSystemPrompt();
        });
    }

    private function buildSystemPrompt(): string
    {
        $enabledFields = BotFilterSetting::allEnabled();

        $filterDescriptions = [];
        foreach ($enabledFields as $setting) {
            $filterDescriptions[] = $this->buildFieldPromptLine($setting);
        }

        if (!$filterDescriptions) {
            $filterDescriptions = [
                '- make (string) — марка',
                '- model (string) — модель',
                '- yearFrom, yearTo (int) — диапазон годов',
                '- priceMin, priceMax (int) — цена в KRW',
                '- mileageMin, mileageMax (int) — пробег в км',
                '- engineMin, engineMax (float) — объем двигателя в литрах',
                '- fuelTypes (string[])',
                '- transmissions (string[])',
                '- bodyTypes (string[])',
                '- driveTypes (string[])',
            ];
        }

        $filtersBlock = implode("\n", $filterDescriptions);

        return <<<PROMPT
Ты — парсер поисковых запросов для автомобилей. Пользователь пишет свободный текст, ты извлекаешь параметры поиска и возвращаешь JSON.

Доступные фильтры:
{$filtersBlock}

Правила:
1. Возвращай ТОЛЬКО JSON, без пояснений
2. Включай только те поля, которые явно упомянуты в тексте
3. "бензин"/"бенз"/"petrol" → fuelTypes: ["Gasoline"]
4. "дизель"/"diesel" → fuelTypes: ["Diesel"]
5. "электро"/"электрический"/"electric" → fuelTypes: ["Electric"]
6. "гибрид"/"hybrid" → fuelTypes: ["Hybrid"]
7. "автомат"/"АКПП"/"automatic" → transmissions: ["Automatic"]
8. "механика"/"МКПП"/"manual" → transmissions: ["Manual"]
9. "полный привод"/"AWD"/"4WD" → driveTypes: ["AWD"]
10. "передний привод"/"FWD" → driveTypes: ["FWD"]
11. "задний привод"/"RWD" → driveTypes: ["RWD"]
12. Числа после марки/модели без контекста — скорее всего объём двигателя (2.0, 2.5, 3.0) → engineMin и engineMax
13. "от X" → Min поле, "до X" → Max поле
14. Пробег определяй по контексту: "пробег от 10000" → mileageMin: 10000
15. Цену определяй по контексту: "до 15000000₩"/"до 15000$" → priceMax
16. "без ДТП"/"без аварий" → hasAccident: false; "с ДТП" → hasAccident: true
17. "без залога"/"чистая" → lienStatuses: ["clean"]
18. "1 владелец"/"один хозяин" → ownersCountMax: 1
19. "без страховых"/"0 страховых" → insuranceCountMax: 0
20. "не затоплена"/"без утоплений" → floodHistory: false
21. Если текст не содержит параметров поиска авто — верни {"error": "not_a_search"}
PROMPT;
    }

    private function buildFieldPromptLine(BotFilterSetting $setting): string
    {
        $name = $setting->field_name;
        $desc = trim((string) ($setting->description ?: $setting->field_label ?: $name));

        return match ($setting->dtype) {
            'int', 'float', 'date' => $this->buildRangePromptLine($name, $setting->dtype, $desc),
            'bool' => '- ' . $this->getFilterParamName($name) . ' (bool) — ' . $desc,
            'enum' => $this->buildEnumPromptLine($name, $setting->enum_values ?? [], $desc),
            default => '- ' . $this->getFilterParamName($name) . ' (string) — ' . $desc,
        };
    }

    private function buildRangePromptLine(string $name, string $type, string $desc): string
    {
        [$min, $max] = $this->getRangeParamNames($name);
        return "- {$min}, {$max} ({$type}) — {$desc}";
    }

    private function buildEnumPromptLine(string $name, array $values, string $desc): string
    {
        $paramName = $this->getFilterParamName($name);
        $cleanValues = array_values(array_filter(array_map('strval', $values), fn ($v) => $v !== ''));
        if (!$cleanValues) {
            return "- {$paramName} (string[]) — {$desc}";
        }

        $valuesStr = implode('", "', $cleanValues);
        return "- {$paramName} (string[]) — {$desc}. Допустимые: \"{$valuesStr}\"";
    }

    /** @return array{0: string, 1: string} */
    private function getRangeParamNames(string $name): array
    {
        return match ($name) {
            'year' => ['yearFrom', 'yearTo'],
            'engine_volume' => ['engineMin', 'engineMax'],
            'insurance_count' => ['insuranceCountMin', 'insuranceCountMax'],
            'owners_count' => ['ownersCountMin', 'ownersCountMax'],
            'repair_cost' => ['repairCostMin', 'repairCostMax'],
            'retail_value' => ['retailValueMin', 'retailValueMax'],
            'seat_count' => ['seatCountMin', 'seatCountMax'],
            'registration_year_month' => ['registrationYearMonthMin', 'registrationYearMonthMax'],
            default => [$this->snakeToCamel($name) . 'Min', $this->snakeToCamel($name) . 'Max'],
        };
    }

    private function getFilterParamName(string $name): string
    {
        return match ($name) {
            'source' => 'sources',
            'title' => 'titleTypes',
            'fuel' => 'fuelTypes',
            'transmission' => 'transmissions',
            'body_type' => 'bodyTypes',
            'drive_type' => 'driveTypes',
            'lien_status' => 'lienStatuses',
            'seizure_status' => 'seizureStatuses',
            'sell_type' => 'sellTypes',
            'color' => 'colors',
            'has_accident' => 'hasAccident',
            'flood_history' => 'floodHistory',
            'total_loss_history' => 'totalLossHistory',
            default => $this->snakeToCamel($name),
        };
    }

    private function snakeToCamel(string $value): string
    {
        return lcfirst(str_replace('_', '', ucwords($value, '_')));
    }
}
