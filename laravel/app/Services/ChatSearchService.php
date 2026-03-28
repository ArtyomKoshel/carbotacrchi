<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatSearchService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('ai.anthropic_key', '');
        $this->model  = config('ai.model', 'claude-haiku-4-5-20251001');
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

        $tolerantQuery = $query->withTolerance();
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
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version'  => '2023-06-01',
                    'content-type'       => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $this->model,
                    'max_tokens' => config('ai.max_tokens', 300),
                    'temperature'=> config('ai.temperature', 0),
                    'system'     => $this->getSystemPrompt(),
                    'messages'   => [
                        ['role' => 'user', 'content' => $text],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('[ChatSearch] API error: ' . $response->status());
                return $this->fallbackParse($text);
            }

            $content = $response->json('content.0.text', '');
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

        return $notes ? implode(', ', $notes) : '';
    }

    private function getSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты — парсер поисковых запросов для автомобилей. Пользователь пишет свободный текст, ты извлекаешь параметры поиска и возвращаешь JSON.

Доступные фильтры:
- make (string) — марка: BMW, Toyota, Honda, Hyundai, Kia, Mercedes-Benz, Ford, Chevrolet, Nissan, Lexus, Audi, Volkswagen, Subaru, Mazda, Dodge, Jeep, Tesla, Genesis, Porsche, Volvo, Ram, Land Rover, Jaguar, Infiniti, Acura, Cadillac, Lincoln, Buick, GMC, Chrysler, Mitsubishi
- model (string) — модель: X3, Camry, Accord, Tucson, Civic, RAV4, F-150, Mustang, Model 3, Sportage, Elantra, Sonata, K5, Sorento, 3 Series, C-Class, Altima, Rogue, Silverado, Malibu, etc.
- yearFrom, yearTo (int) — диапазон годов
- priceMin, priceMax (int) — цена в USD
- mileageMin, mileageMax (int) — пробег в км
- engineMin, engineMax (float) — объём двигателя в литрах
- fuelTypes (string[]) — допустимые: "Gasoline", "Diesel", "Hybrid", "Electric"
- transmissions (string[]) — допустимые: "Automatic", "Manual", "CVT"
- bodyTypes (string[]) — допустимые: "Sedan", "SUV", "Truck", "Coupe", "Hatchback", "Wagon", "Van", "Convertible", "Crossover"
- driveTypes (string[]) — допустимые: "FWD", "RWD", "AWD", "4WD"
- sources (string[]) — допустимые: "copart", "iai", "manheim", "encar", "kbcha"

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
15. Цену определяй по контексту: "до 15000$"/"до $15000" → priceMax: 15000
16. Если текст не содержит параметров поиска авто — верни {"error": "not_a_search"}
PROMPT;
    }
}
