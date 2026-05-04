<?php

namespace App\Services;

use App\Models\BotFilterSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatSearchService
{
    private string $apiKey;
    private string $apiUrl;
    private string $model;
    private bool $lastParseWasAi = false;
    /** @var array<string, array<int, string>>|null */
    private static ?array $makesModels = null;

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
     * @return array{query: SearchQuery, tolerantQuery: SearchQuery, description: string, toleranceNote: string, isAi: bool}|null
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
            'isAi'          => $this->lastParseWasAi,
        ];
    }

    private function parseQuery(string $text): ?array
    {
        if (!$this->isAvailable()) {
            $this->lastParseWasAi = false;
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
                $this->lastParseWasAi = false;
                return $this->fallbackParse($text);
            }

            $content = $response->json('choices.0.message.content', '');
            $json    = $this->extractJson($content);

            if (!$json || isset($json['error'])) {
                return null;
            }

            $this->lastParseWasAi = true;
            return $json;
        } catch (\Throwable $e) {
            Log::error('[ChatSearch] ' . $e->getMessage());
            $this->lastParseWasAi = false;
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

        // ── Brand aliases (Russian/transliterated names) ─────────────────────
        $brandAliases = [
            'мерседес' => 'Mercedes-Benz', 'мерс'   => 'Mercedes-Benz',
            'бмв'      => 'BMW',
            'хёндай'   => 'Hyundai',       'хундай' => 'Hyundai', 'хендай' => 'Hyundai',
            'киа'      => 'Kia',
            'тойота'   => 'Toyota',
            'хонда'    => 'Honda',
            'ниссан'   => 'Nissan',
            'ауди'     => 'Audi',
            'фольксваген' => 'Volkswagen', 'фольц'  => 'Volkswagen',
            'вольво'   => 'Volvo',
            'порше'    => 'Porsche',
            'лексус'   => 'Lexus',
            'субару'   => 'Subaru',
            'мазда'    => 'Mazda',
            'форд'     => 'Ford',
            'дженезис' => 'Genesis', 'генезис' => 'Genesis',
            'шевроле'  => 'Chevrolet',
        ];

        // ── Numeric BMW series shortcuts ──────────────────────────────────────
        $numericSeries = [
            'bmw' => [
                '1' => '1 Series', '2' => '2 Series', '3' => '3 Series',
                '4' => '4 Series', '5' => '5 Series', '6' => '6 Series',
                '7' => '7 Series', '8' => '8 Series',
            ],
        ];

        // ── Russian model aliases ─────────────────────────────────────────────
        $modelAliases = [
            'mercedes-benz' => [
                'е класс' => 'E-Class', 'е-класс' => 'E-Class',
                'с класс' => 'C-Class', 'с-класс' => 'C-Class',
                'а класс' => 'A-Class', 'а-класс' => 'A-Class',
                'г класс' => 'G-Class', 'г-класс' => 'G-Class',
                'сл класс' => 'SL', 'cls класс' => 'CLS',
            ],
            'bmw' => [
                '1 серия' => '1 Series', '2 серия' => '2 Series',
                '3 серия' => '3 Series', '4 серия' => '4 Series',
                '5 серия' => '5 Series', '7 серия' => '7 Series',
            ],
        ];

        $makes = $this->getMakesModels();

        // Detect make: try aliases first, then JSON names
        $detectedMake = null;
        foreach ($brandAliases as $alias => $canonical) {
            if (mb_strpos($text, $alias) !== false) {
                $detectedMake = $canonical;
                break;
            }
        }
        if (!$detectedMake) {
            foreach ($makes as $make => $unused) {
                if (mb_stripos($text, mb_strtolower($make)) !== false) {
                    $detectedMake = $make;
                    break;
                }
            }
        }

        if ($detectedMake) {
            $result['make'] = $detectedMake;
            $makeLower      = mb_strtolower($detectedMake);

            // Russian model aliases
            if (isset($modelAliases[$makeLower])) {
                foreach ($modelAliases[$makeLower] as $alias => $series) {
                    if (mb_strpos($text, $alias) !== false) {
                        $result['model'] = $series;
                        break;
                    }
                }
            }

            // Numeric series (BMW 7 → 7 Series)
            if (empty($result['model']) && isset($numericSeries[$makeLower])) {
                foreach ($numericSeries[$makeLower] as $digit => $series) {
                    if (preg_match('/\b' . preg_quote($digit, '/') . '\b/', $text)) {
                        $result['model'] = $series;
                        break;
                    }
                }
            }

            // English model names from JSON
            if (empty($result['model'])) {
                foreach (($makes[$detectedMake] ?? []) as $model) {
                    if (mb_stripos($text, mb_strtolower($model)) !== false) {
                        $result['model'] = $model;
                        break;
                    }
                }
            }
        }

        // ── Year ──────────────────────────────────────────────────────────────
        if (preg_match('/(\d{4})\s*[-–]\s*(\d{4})/', $text, $m)) {
            $result['yearFrom'] = (int) $m[1];
            $result['yearTo']   = (int) $m[2];
        } elseif (preg_match('/(?:от|from|с)\s*(\d{4})\s*(?:г|год|года)?/u', $text, $m)) {
            $result['yearFrom'] = (int) $m[1];
        } elseif (preg_match('/(?:до|по|to)\s*(\d{4})\s*(?:г|год|года)?/u', $text, $m)) {
            $result['yearTo'] = (int) $m[1];
        } elseif (preg_match('/(\d{4})\s*(?:г|год|года)/u', $text, $m)) {
            $yr = (int) $m[1];
            if ($yr >= 1990 && $yr <= (int) date('Y') + 1) {
                $result['yearFrom'] = $yr;
                $result['yearTo']   = $yr;
            }
        }

        // ── Price ─────────────────────────────────────────────────────────────
        if (preg_match('/(?:цена?|price|стоим\w*)\s*до\s*[\$₩]?\s*([\d\s,]+)/ui', $text, $m)) {
            $val = (int) preg_replace('/[\s,]/', '', $m[1]);
            if ($val > 1000) $result['priceMax'] = $val;
        } elseif (preg_match('/до\s*[\$₩]?\s*([\d\s,]+)\s*(?:\$|₩|долл|usd|krw)/ui', $text, $m)) {
            $val = (int) preg_replace('/[\s,]/', '', $m[1]);
            if ($val > 1000) $result['priceMax'] = $val;
        }
        if (preg_match('/(?:цена?|price|стоим\w*)\s*от\s*[\$₩]?\s*([\d\s,]+)/ui', $text, $m)) {
            $val = (int) preg_replace('/[\s,]/', '', $m[1]);
            if ($val > 1000) $result['priceMin'] = $val;
        } elseif (preg_match('/(?:цена?|price|стоим\w*)\s*[\$₩]?\s*([\d\s,]+)\s*(?:\$|₩|долл|usd|krw)?/ui', $text, $m)) {
            $val = (int) preg_replace('/[\s,]/', '', $m[1]);
            if ($val > 1000) {
                $result['priceMin'] = $val;
                $result['priceMax'] = $val;
            }
        }

        // ── Mileage ───────────────────────────────────────────────────────────
        // "пробег от 100 000" / "пробег 100000 км" / "100 000 пробег"
        if (preg_match('/(?:пробег|mileage)[^\d]*([\d\s,]+)\s*[-–]\s*([\d\s,]+)/ui', $text, $m)) {
            $result['mileageMin'] = (int) preg_replace('/[\s,]/', '', $m[1]);
            $result['mileageMax'] = (int) preg_replace('/[\s,]/', '', $m[2]);
        } elseif (preg_match('/(?:пробег|mileage)\s*(?:от|from|min)\s*([\d\s,]+)/ui', $text, $m)) {
            $result['mileageMin'] = (int) preg_replace('/[\s,]/', '', $m[1]);
        } elseif (preg_match('/(?:пробег|mileage)\s*(?:до|to|max)\s*([\d\s,]+)/ui', $text, $m)) {
            $result['mileageMax'] = (int) preg_replace('/[\s,]/', '', $m[1]);
        } elseif (preg_match('/(?:пробег|mileage)\s*([\d][\d\s,]*)/ui', $text, $m)) {
            $val = (int) preg_replace('/[\s,]/', '', $m[1]);
            if ($val > 100 && $val < 1000000) {
                $result['mileageMin'] = $val;
                $result['mileageMax'] = $val;
            }
        } elseif (preg_match('/([\d][\d\s,]*)\s*(?:пробег|км|km)\s*пробег/ui', $text, $m)) {
            $val = (int) preg_replace('/[\s,]/', '', $m[1]);
            if ($val > 100 && $val < 1000000) {
                $result['mileageMin'] = $val;
                $result['mileageMax'] = $val;
            }
        } elseif (preg_match('/([\d][\d\s,]+)\s*(?:пробег)/ui', $text, $m)) {
            $val = (int) preg_replace('/[\s,]/', '', $m[1]);
            if ($val > 100 && $val < 1000000) {
                $result['mileageMin'] = $val;
                $result['mileageMax'] = $val;
            }
        }

        // ── Fuel ─────────────────────────────────────────────────────────────
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

        // ── Transmission ─────────────────────────────────────────────────────
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

        // ── Engine volume ─────────────────────────────────────────────────────
        if (preg_match('/(\d+[.,]\d+)\s*(?:л|l|литр|litr)?/ui', $text, $m)) {
            $vol = (float) str_replace(',', '.', $m[1]);
            if ($vol >= 0.5 && $vol <= 9.0) {
                $result['engineMin'] = $vol;
                $result['engineMax'] = $vol;
            }
        } elseif (preg_match('/(?:двигатель|объём|объем|мотор|engine)[\s:]+(\d+)/ui', $text, $m)) {
            $vol = (float) $m[1];
            if ($vol >= 1 && $vol <= 9) {
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

        // Mileage: show actual range, e.g. "пробег: 90,000–110,000 км"
        if ($original->mileageMin !== $tolerant->mileageMin || $original->mileageMax !== $tolerant->mileageMax) {
            $lo = $tolerant->mileageMin > 0 ? number_format($tolerant->mileageMin, 0, '.', ',') : null;
            $hi = $tolerant->mileageMax > 0 ? number_format($tolerant->mileageMax, 0, '.', ',') : null;
            $notes[] = 'пробег: ' . $this->fmtRange($lo, $hi, 'км');
        }

        // Price
        if ($original->priceMin !== $tolerant->priceMin || $original->priceMax !== $tolerant->priceMax) {
            $lo = $tolerant->priceMin > 0 ? '₩' . number_format($tolerant->priceMin, 0, '.', ',') : null;
            $hi = $tolerant->priceMax > 0 ? '₩' . number_format($tolerant->priceMax, 0, '.', ',') : null;
            $notes[] = 'цена: ' . $this->fmtRange($lo, $hi);
        }

        // Engine
        if ($original->engineMin !== $tolerant->engineMin || $original->engineMax !== $tolerant->engineMax) {
            $lo = $tolerant->engineMin > 0 ? (string) $tolerant->engineMin : null;
            $hi = $tolerant->engineMax > 0 ? (string) $tolerant->engineMax : null;
            $notes[] = 'двигатель: ' . $this->fmtRange($lo, $hi, 'л');
        }

        // Year
        if ($original->yearFrom !== $tolerant->yearFrom || $original->yearTo !== $tolerant->yearTo) {
            $lo = $tolerant->yearFrom > 0 ? (string) $tolerant->yearFrom : null;
            $hi = $tolerant->yearTo   > 0 ? (string) $tolerant->yearTo   : null;
            $notes[] = 'год: ' . $this->fmtRange($lo, $hi);
        }

        // Insurance
        if ($original->insuranceCountMin !== $tolerant->insuranceCountMin || $original->insuranceCountMax !== $tolerant->insuranceCountMax) {
            $lo = $tolerant->insuranceCountMin > 0 ? (string) $tolerant->insuranceCountMin : null;
            $hi = ($tolerant->insuranceCountMax > 0 || $original->insuranceCountMax === 0)
                ? (string) $tolerant->insuranceCountMax : null;
            $notes[] = 'страховых: ' . $this->fmtRange($lo, $hi);
        }

        // Owners
        if ($original->ownersCountMin !== $tolerant->ownersCountMin || $original->ownersCountMax !== $tolerant->ownersCountMax) {
            $lo = $tolerant->ownersCountMin > 0 ? (string) $tolerant->ownersCountMin : null;
            $hi = ($tolerant->ownersCountMax > 0 || $original->ownersCountMax === 0)
                ? (string) $tolerant->ownersCountMax : null;
            $notes[] = 'владельцев: ' . $this->fmtRange($lo, $hi);
        }

        return $notes ? implode(', ', $notes) : '';
    }

    private function fmtRange(?string $lo, ?string $hi, string $unit = ''): string
    {
        $suffix = $unit ? ' ' . $unit : '';
        if ($lo !== null && $hi !== null && $lo !== $hi) {
            return $lo . '–' . $hi . $suffix;
        }
        if ($lo !== null) return $lo . $suffix;
        if ($hi !== null) return $hi . $suffix;
        return '?';
    }

    private function getSystemPrompt(): string
    {
        return $this->buildSystemPrompt();
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
21. Если указано точное число без слов "от/до" для range-поля (пробег, цена, год, объем), ставь Min и Max равными
22. Если текст не содержит параметров поиска авто — верни {"error": "not_a_search"}
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

    /** @return array<string, array<int, string>> */
    private function getMakesModels(): array
    {
        if (self::$makesModels !== null) {
            return self::$makesModels;
        }

        $path = storage_path('app/data/makes_models.json');
        if (!is_file($path) || !is_readable($path)) {
            self::$makesModels = [];
            return self::$makesModels;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        self::$makesModels = is_array($decoded) ? $decoded : [];

        return self::$makesModels;
    }
}
