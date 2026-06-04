<?php

namespace App\Services;

use App\Models\BotFilterSetting;
use App\Models\EncarOptionCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatSearchService
{
    private string $apiKey;
    private string $apiUrl;
    private string $model;
    private bool $lastParseWasAi          = false;
    private bool $lastParseWasRefinement  = false;
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
     * @param array{query: array, total: int, description: string}|null $prevState
     * @return array{query: SearchQuery, tolerantQuery: SearchQuery, description: string, toleranceNote: string, isAi: bool, refined: bool}|null
     */
    public function parseAndSearch(string $text, ?array $prevState = null): ?array
    {
        $parsed = $this->parseQuery($text, $prevState);
        if ($parsed === null) {
            return null;
        }

        $query        = SearchQuery::fromArray($parsed);
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
            'refined'       => $this->lastParseWasRefinement,
        ];
    }

    private function parseQuery(string $text, ?array $prevState = null): ?array
    {
        if (!$this->isAvailable()) {
            $this->lastParseWasAi          = false;
            $this->lastParseWasRefinement  = false;
            $parsed = $this->fallbackParse($text);
            if ($parsed !== null && $prevState !== null) {
                return $this->mergeWithPrevious($prevState['query'], $parsed);
            }
            return $parsed;
        }

        try {
            $messages = [
                ['role' => 'system', 'content' => $this->getSystemPrompt()],
            ];

            if ($prevState !== null) {
                $prevDesc    = $prevState['description'] ?? '';
                $prevTotal   = $prevState['total'] ?? 0;
                $prevJson    = json_encode($prevState['query'] ?? [], JSON_UNESCAPED_UNICODE);
                $messages[]  = [
                    'role'    => 'assistant',
                    'content' => "Предыдущий поиск: {$prevDesc} (найдено {$prevTotal}). JSON: {$prevJson}",
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $text];

            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->post($this->apiUrl, [
                    'model'           => $this->model,
                    'max_tokens'      => config('ai.max_tokens', 300),
                    'temperature'     => config('ai.temperature', 0),
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => $messages,
                ]);

            if (!$response->successful()) {
                Log::warning('[ChatSearch] API error: ' . $response->status() . ' ' . $response->body());
                $this->lastParseWasAi         = false;
                $this->lastParseWasRefinement = false;
                return $this->fallbackParse($text);
            }

            $content = $response->json('choices.0.message.content', '');
            $json    = $this->extractJson($content);

            if (!$json || !empty($json['not_a_search']) || isset($json['error'])) {
                return null;
            }

            unset($json['not_a_search']);
            $this->lastParseWasAi = true;

            // Detect if this is a refinement or a new search
            $meaningful = array_filter($json, fn ($v) => $v !== null && $v !== '' && $v !== []);
            $isDefinitelyNew = $this->isDefinitelyNewSearch($json, $prevState);

            $this->lastParseWasRefinement = $prevState !== null
                && !$isDefinitelyNew
                && count($meaningful) <= 4;

            if ($this->lastParseWasRefinement) {
                return $this->mergeWithPrevious($prevState['query'], $json);
            }

            return $json;
        } catch (\Throwable $e) {
            Log::error('[ChatSearch] ' . $e->getMessage());
            $this->lastParseWasAi         = false;
            $this->lastParseWasRefinement = false;
            return $this->fallbackParse($text);
        }
    }

    /**
     * Returns true when the new AI response is clearly a brand-new search,
     * not a follow-up refinement of the previous one.
     */
    private function isDefinitelyNewSearch(array $newJson, ?array $prevState): bool
    {
        if ($prevState === null) {
            return true;
        }

        $prevQuery = $prevState['query'] ?? [];

        // Different make → definitely new search
        $newMake  = strtolower(trim((string) ($newJson['make']     ?? '')));
        $prevMake = strtolower(trim((string) ($prevQuery['make']   ?? '')));
        if ($newMake !== '' && $prevMake !== '' && $newMake !== $prevMake) {
            return true;
        }

        // Different model → definitely new search
        $newModel  = strtolower(trim((string) ($newJson['model']   ?? '')));
        $prevModel = strtolower(trim((string) ($prevQuery['model'] ?? '')));
        if ($newModel !== '' && $prevModel !== '' && $newModel !== $prevModel) {
            return true;
        }

        // Many new fields (≥5) → full new query, not a refinement
        $meaningful = array_filter($newJson, fn ($v) => $v !== null && $v !== '' && $v !== []);
        if (count($meaningful) >= 5) {
            return true;
        }

        return false;
    }

    private function mergeWithPrevious(array $base, array $delta): array
    {
        $merged = $base;
        foreach ($delta as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $merged[$key] = $value;
        }
        // Always reset pagination
        unset($merged['limit'], $merged['offset']);
        return $merged;
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

        // ── Date filters (listed_at / first_reg_date) ───────────────────────
        if (preg_match('/(?:свежие|новые\s*объявлен|за\s*(?:последн|эту)\s*недел)/ui', $text)) {
            $result['listedAfter'] = date('Y-m-d', strtotime('-7 days'));
        } elseif (preg_match('/за\s*(?:последний\s*)?месяц/ui', $text)) {
            $result['listedAfter'] = date('Y-m-d', strtotime('-30 days'));
        } elseif (preg_match('/за\s*(\d+)\s*дн/ui', $text, $m)) {
            $result['listedAfter'] = date('Y-m-d', strtotime("-{$m[1]} days"));
        }

        if (preg_match('/регистрац\w*\s*(?:после|от|с)\s*(\d{4})/ui', $text, $m)) {
            $result['firstRegAfter'] = $m[1] . '-01-01';
        } elseif (preg_match('/регистрац\w*\s*(?:до|по)\s*(\d{4})/ui', $text, $m)) {
            $result['firstRegBefore'] = $m[1] . '-12-31';
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
                '- priceMin, priceMax (int) — цена в ₩ (KRW)',
                '- mileageMin, mileageMax (int) — пробег в км',
                '- engineMin, engineMax (float) — объем двигателя в литрах',
                '- fuelTypes (string[])',
                '- transmissions (string[])',
                '- bodyTypes (string[])',
                '- driveTypes (string[])',
            ];
        }

        $filtersBlock   = implode("\n", $filterDescriptions);
        $makesBlock     = $this->buildMakesContext();
        $statsBlock     = $this->buildDbStatsContext();
        $optionsBlock   = $this->buildOptionsContext();
        $trimExamples   = $this->buildTrimExamplesForPrompt();
        $today          = date('Y-m-d');

        return <<<PROMPT
Ты — парсер поисковых запросов для автомобилей на корейских аукционах. Пользователь пишет свободный текст на русском/английском, ты извлекаешь параметры поиска и возвращаешь JSON.

Доступные фильтры:
{$filtersBlock}

{$statsBlock}

{$optionsBlock}

{$makesBlock}

Правила:
1. Возвращай ТОЛЬКО валидный JSON объект, без пояснений и markdown
2. Включай только те поля, которые ЯВНО упомянуты или однозначно подразумеваются в тексте
3. Если текст не содержит параметров поиска авто — верни {"not_a_search": true}
4. "бензин"/"бенз"/"petrol" → fuelTypes: ["Gasoline"]
5. "дизель"/"diesel" → fuelTypes: ["Diesel"]
6. "электро"/"электрический"/"electric" → fuelTypes: ["Electric"]
7. "гибрид"/"hybrid" → fuelTypes: ["Hybrid"]
8. "автомат"/"АКПП"/"automatic" → transmissions: ["Automatic"]
9. "механика"/"МКПП"/"manual" → transmissions: ["Manual"]
10. "полный привод"/"AWD"/"4WD" → driveTypes: ["AWD"]
11. "передний привод"/"FWD" → driveTypes: ["FWD"]
12. "задний привод"/"RWD" → driveTypes: ["RWD"]
13. "седан"/"sedan" → bodyTypes: ["Sedan"]; "кроссовер"/"SUV" → bodyTypes: ["SUV"]; "хэтчбек"/"hatchback" → bodyTypes: ["Hatchback"]; "универсал"/"wagon" → bodyTypes: ["Wagon"]; "купе"/"coupe" → bodyTypes: ["Coupe"]; "минивэн"/"van" → bodyTypes: ["Van"]
14. Числа 1.6, 2.0, 2.5, 3.0 и т.д. рядом с маркой/моделью — объём двигателя → engineMin и engineMax
15. "от X" → Min поле, "до X" → Max поле
16. Пробег определяй по контексту: "пробег от 10000" → mileageMin: 10000
17. Цену определяй по контексту. Цены хранятся в ₩ (KRW). 1$ ≈ 1300₩. "до 15000$" → priceMax: 19500000; "до 20 млн вон" → priceMax: 20000000
18. "без ДТП"/"без аварий" → hasAccident: false; "с ДТП" → hasAccident: true
19. "без залога"/"чистая" → lienStatuses: ["clean"]
20. "1 владелец"/"один хозяин" → ownersCountMax: 1
21. "без страховых"/"0 страховых" → insuranceCountMax: 0
22. "не затоплена"/"без утоплений" → floodHistory: false
23. Если указано точное число для range-поля (пробег, цена, год, объем) БЕЗ слов "от/до", ставь Min и Max равными
24. Комплектация/trim: всегда возвращай корейское значение из DB. Маппинг EN→KR: {$trimExamples}
25. Поколение/generation: "G30", "W213", "CN7", "NQ5" и т.д. → generation: "G30"
26. Марку и модель пиши ТОЧНО как в списке доступных (см. ниже). Русские варианты маппи: мерседес→Mercedes-Benz, бмв→BMW, хендай/хёндай→Hyundai, тойота→Toyota, порше→Porsche, ауди→Audi и т.д.
27. "свежие"/"новые объявления"/"за последнюю неделю"/"за месяц" → listedAfter: "YYYY-MM-DD" (вычисли дату от текущей)
28. Дата первой регистрации: "дата регистрации 2025"/"зарегистрирована в 2023"/"1 регистрация 2025"/"регистрация 2022"/"рег. 2024" → если конкретный год без "после/до" → firstRegAfter: "YYYY-01-01" И firstRegBefore: "YYYY-12-31"; "регистрация после 2022"→ firstRegAfter: "2022-01-01"; "регистрация до 2023"→ firstRegBefore: "2023-12-31"; "регистрация 2022-2024" → firstRegAfter: "2022-01-01" + firstRegBefore: "2024-12-31"
29. Текущая дата: {$today}

Особые случаи:
- Если запрос не поддаётся ни одному фильтру (напр. "тихий", "экономичный") — верни {"not_a_search": true} чтобы пользователь уточнил
- Для оснащения/опций (кожаный салон, люк, подогрев сидений и т.д.) — используй поле options[] с кодами из списка ниже

Follow-up запросы (если в контексте есть предыдущий поиск):
- "покажи дешевле" / "подешевле" → уменьши priceMax на 20%, убери priceMin
- "дороже" / "подороже" → увеличь priceMin или priceMax на 20%
- "новее" / "свежее" → увеличь yearFrom на 2-3 года
- "с меньшим пробегом" → уменьши mileageMax на 30%
- "без ДТП" → hasAccident: false
- "автомат" / "механика" → добавь transmissions
- "только Encar" / "только KBChacha" → обнови sources
- Для follow-up возвращай ТОЛЬКО изменённые поля (не весь предыдущий запрос)

Примеры:
User: "хендай соната 2020 автомат до 15000$"
→ {"make": "Hyundai", "model": "Sonata", "yearFrom": 2020, "yearTo": 2020, "transmissions": ["Automatic"], "priceMax": 19500000}

User: "бмв 5 серия g30 дизель пробег до 100000"
→ {"make": "BMW", "model": "5 Series", "generation": "G30", "fuelTypes": ["Diesel"], "mileageMax": 100000}

User: "кроссовер до 20 млн вон без дтп 1 владелец"
→ {"bodyTypes": ["SUV"], "priceMax": 20000000, "hasAccident": false, "ownersCountMax": 1}

User: "привет как дела"
→ {"not_a_search": true}

User: "тойота камри 2.5 белая"
→ {"make": "Toyota", "model": "Camry", "engineMin": 2.5, "engineMax": 2.5, "colors": ["White"]}

User: "дата регистрации 2025"
→ {"firstRegAfter": "2025-01-01", "firstRegBefore": "2025-12-31"}

User: "1 регистрация 2024 год"
→ {"firstRegAfter": "2024-01-01", "firstRegBefore": "2024-12-31"}

User: "регистрация после 2023"
→ {"firstRegAfter": "2023-01-01"}
PROMPT;
    }

    private function buildTrimExamplesForPrompt(): string
    {
        try {
            $map = \App\Models\Translation::promptMap('trim');
            if (empty($map)) {
                return '"Prestige"→"프레스티지", "Premium"→"프리미엄", "Noblesse"→"노블레스", "Luxury"→"럭셔리", "Signature"→"시그니처", "Modern"→"모던"';
            }
            $parts = [];
            foreach ($map as $en => $kr) {
                $parts[] = '"' . $en . '"→"' . $kr . '"';
                if (count($parts) >= 50) break;
            }
            return implode(', ', $parts);
        } catch (\Throwable) {
            return '"Prestige"→"프레스티지", "Premium"→"프리미엄", "Noblesse"→"노블레스", "Luxury"→"럭셔리", "Signature"→"시그니처", "Modern"→"모던"';
        }
    }

    private function buildOptionsContext(): string
    {
        return Cache::remember('chat_search_options_context', 3600, function () {
            try {
                $catalog = EncarOptionCatalog::getCached();
                if ($catalog->isEmpty()) {
                    return '';
                }

                $lines = [
                    'Доступные опции Encar для фильтра options[] — используй ТОЧНЫЙ code:',
                    '(options[] поддерживает несколько кодов одновременно — AND логика)',
                ];

                foreach ($catalog->sortBy('sort_order') as $opt) {
                    $label = $opt->name_ru ?? $opt->name_en ?? $opt->name_kr ?? $opt->code;
                    $extra = [];
                    if ($opt->name_en && $opt->name_en !== $label) $extra[] = $opt->name_en;
                    if ($opt->name_kr)                              $extra[] = $opt->name_kr;
                    $suffix = $extra ? ' (' . implode(' / ', $extra) . ')' : '';
                    $lines[] = sprintf('  "%s" → %s%s', $opt->code, $label, $suffix);
                }

                $lines[] = 'Пример: пользователь "с кожаным салоном и люком" → options: ["CODE_LEATHER", "CODE_SUNROOF"]';

                return implode("\n", $lines);
            } catch (\Throwable) {
                return '';
            }
        });
    }

    private function buildMakesContext(): string
    {
        $makes = $this->getMakesModels();
        if (empty($makes)) {
            return '';
        }

        $lines = ['Доступные марки и модели (используй ТОЛЬКО эти названия):'];
        $count = 0;
        foreach ($makes as $make => $models) {
            if ($count >= 50) {
                $lines[] = '... и другие';
                break;
            }
            $modelsList = implode(', ', array_slice($models, 0, 15));
            $extra      = count($models) > 15 ? ' ...' : '';
            $lines[]    = "- {$make}: {$modelsList}{$extra}";
            $count++;
        }

        return implode("\n", $lines);
    }

    private function buildDbStatsContext(): string
    {
        return Cache::remember('chat_search_db_stats', 600, function () {
            try {
                $stats = DB::table('lots')
                    ->where('is_active', true)
                    ->selectRaw('
                        MIN(price) as price_min, MAX(price) as price_max,
                        ROUND(AVG(price)) as price_avg,
                        MIN(year) as year_min, MAX(year) as year_max,
                        MIN(mileage) as mileage_min, MAX(mileage) as mileage_max,
                        COUNT(*) as total
                    ')
                    ->first();

                if (!$stats || !$stats->total) {
                    return '';
                }

                $fmt = fn ($n) => number_format((int) $n, 0, '.', ' ');

                return implode("\n", [
                    "Реальные данные в базе ({$fmt($stats->total)} активных лотов):",
                    "- Цена: ₩{$fmt($stats->price_min)} – ₩{$fmt($stats->price_max)} (средняя ₩{$fmt($stats->price_avg)})",
                    "- Год: {$stats->year_min} – {$stats->year_max}",
                    "- Пробег: {$fmt($stats->mileage_min)} – {$fmt($stats->mileage_max)} км",
                    "- «Дешёвый» ≈ ниже средней цены; «свежий/новый» ≈ год > " . (date('Y') - 2),
                    "- «Много пробега» ≈ > 150 000 км; «мало пробега» ≈ < 50 000 км",
                ]);
            } catch (\Throwable) {
                return '';
            }
        });
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
            'listed_at' => ['listedAfter', 'listedBefore'],
            'first_reg_date' => ['firstRegAfter', 'firstRegBefore'],
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

        self::$makesModels = Cache::remember('chat_search_makes_models', 600, function () {
            try {
                $rows = DB::table('lots')
                    ->where('is_active', true)
                    ->whereNotNull('make')
                    ->where('make', '!=', '')
                    ->select(['make', 'model'])
                    ->distinct()
                    ->orderBy('make')
                    ->orderBy('model')
                    ->get();

                $byMake = [];
                foreach ($rows as $row) {
                    $make = trim((string) ($row->make ?? ''));
                    if ($make === '') continue;
                    $model = trim((string) ($row->model ?? ''));
                    $byMake[$make] ??= [];
                    if ($model !== '' && !in_array($model, $byMake[$make], true)) {
                        $byMake[$make][] = $model;
                    }
                }
                ksort($byMake);
                return $byMake;
            } catch (\Throwable) {
                return [];
            }
        });

        return self::$makesModels;
    }
}
