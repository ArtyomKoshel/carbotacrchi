<?php

namespace App\Services;

use App\Catalog\CatalogNames;
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

        if (trim((string) ($parsed['generation'] ?? '')) === '') {
            $generation = $this->detectGeneration($text, $parsed);
            if ($generation !== null) {
                $parsed['generation'] = $generation;
            }
        }
        // "соната dn8": the model was not understood but the code names it
        if (trim((string) ($parsed['model'] ?? '')) === '' && trim((string) ($parsed['generation'] ?? '')) !== '') {
            $model = $this->modelOfGeneration((string) ($parsed['make'] ?? ''), (string) $parsed['generation']);
            if ($model !== null) {
                $parsed['model'] = $model;
            }
        }

        // the AI's trim/badge ("Premium 45 TFSI") is read with the words against the catalog,
        // not as one exact name: a part it got wrong must not make the search empty
        $said = trim($text . ' ' . ($parsed['trim'] ?? '') . ' ' . ($parsed['badge'] ?? ''));
        unset($parsed['trim'], $parsed['badge']);

        $query        = SearchQuery::fromArray($parsed);
        $query->limit = 50;
        $this->catalogWords($query, $said);

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

    /**
     * The modification and complectation named in the words ("а5 45 tfsi quattro s line"), by the
     * reference catalog of the model and make found: neither the AI nor the keywords know them.
     * Named words win over the filters already set; a modification outside the generations
     * set drops those generations.
     */
    private function catalogWords(SearchQuery $query, string $text): void
    {
        if ($query->matchesNothing) {
            return;
        }
        $names = app(CatalogNames::class);
        try {
            if ($query->refModelId > 0) {
                $mods = $names->modificationsIn($query->refModelId, $text, $query->refGenerationIds);
                if ($mods === [] && $query->refGenerationIds !== []
                    && ($mods = $names->modificationsIn($query->refModelId, $text)) !== []) {
                    $query->refGenerationIds = [];
                }
                if ($mods !== []) {
                    $query->refModificationIds = $mods;
                }
            }
            if ($query->refMakeId > 0) {
                [$complectations] = $names->complectationsIn($query->refMakeId, $text);
                if ($complectations !== []) {
                    $query->refComplectationIds = $complectations;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[ChatSearch] catalogWords: ' . $e->getMessage());
        }
    }

    /**
     * Recover a chassis code the AI dropped ("bmw 3 g20 …" parsed without generation,
     * so F30 cars got mixed in). A token like G20 / F30 / W213 / DN8 counts only if it is
     * a generation code in the reference catalog of the parsed make/model with active
     * lots — random words never become a filter.
     */
    private function detectGeneration(string $text, array $parsed): ?string
    {
        if (!preg_match_all('/(?<![\p{L}\p{N}])([A-Za-z]{1,3}\d{1,3}[A-Za-z]?)(?![\p{L}\p{N}])/u', $text, $m)) {
            return null;
        }

        $names  = app(CatalogNames::class);
        $make   = trim((string) ($parsed['make'] ?? ''));
        $model  = trim((string) ($parsed['model'] ?? ''));
        $makeId = $make !== '' ? $names->make($make) : null;
        $modelId = $model !== '' ? ($names->model($makeId, $model)['id'] ?? null) : null;
        if (($make !== '' && $makeId === null) || ($model !== '' && $modelId === null)) {
            return null;
        }

        foreach (array_unique(array_map('strtoupper', $m[1])) as $code) {
            try {
                $found = DB::table('ref_generations as g')
                    ->join('ref_models as m', 'm.id', '=', 'g.model_id')
                    ->where(fn ($q) => $q->where('g.code', $code)->orWhere('g.code', 'like', $code . ' · %'))
                    ->when($makeId !== null, fn ($q) => $q->where('m.make_id', $makeId))
                    ->when($modelId !== null, fn ($q) => $q->where('m.id', $modelId))
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('lots')
                        ->whereColumn('lots.ref_generation_id', 'g.id')->where('lots.is_active', 1))
                    ->exists();
            } catch (\Throwable $e) {
                Log::warning('[ChatSearch] detectGeneration: ' . $e->getMessage());
                return null;
            }
            if ($found) {
                return $code;
            }
        }

        return null;
    }

    /** The one model (of the make, if named) whose generation has that code and active lots. */
    private function modelOfGeneration(string $make, string $code): ?string
    {
        $code = preg_match('/\(([^)]+)\)\s*$/u', $code, $m) ? $m[1] : trim($code);
        $makeId = trim($make) !== '' ? app(CatalogNames::class)->make($make) : null;
        if (trim($make) !== '' && $makeId === null) {
            return null;
        }
        $models = DB::table('ref_generations as g')
            ->join('ref_models as m', 'm.id', '=', 'g.model_id')
            ->where(fn ($q) => $q->where('g.code', $code)->orWhere('g.code', 'like', $code . ' · %'))
            ->when($makeId !== null, fn ($q) => $q->where('m.make_id', $makeId))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('lots')
                ->whereColumn('lots.ref_generation_id', 'g.id')->where('lots.is_active', 1))
            ->distinct()->pluck('m.name');

        return $models->count() === 1 ? (string) $models->first() : null;
    }

    private function parseQuery(string $text, ?array $prevState = null): ?array
    {
        if (!$this->isAvailable()) {
            $this->lastParseWasAi = false;
            return $this->fallbackWithPrevious($text, $prevState);
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
                    'max_tokens'      => max(600, (int) config('ai.max_tokens', 300)),
                    'temperature'     => config('ai.temperature', 0),
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => $messages,
                ]);

            if (!$response->successful()) {
                Log::warning('[ChatSearch] API error: ' . $response->status() . ' ' . $response->body());
                $this->lastParseWasAi         = false;
                return $this->fallbackWithPrevious($text, $prevState);
            }

            $content = $response->json('choices.0.message.content', '');
            $json    = $this->extractJson($content);

            if (!$json || !empty($json['not_a_search']) || isset($json['error'])) {
                return null;
            }

            unset($json['not_a_search']);
            $json = $this->fillGaps($json, $text, $prevState['query']['make'] ?? null);
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
            $this->lastParseWasAi = false;
            return $this->fallbackWithPrevious($text, $prevState);
        }
    }

    private function fallbackWithPrevious(string $text, ?array $prevState): ?array
    {
        $parsed = $this->fallbackParse($text, $prevState['query']['make'] ?? null);
        $this->lastParseWasRefinement = $parsed !== null && $prevState !== null && !$this->isDefinitelyNewSearch($parsed, $prevState);
        return $this->lastParseWasRefinement ? $this->mergeWithPrevious($prevState['query'], $parsed) : $parsed;
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
        $base = $this->dropReplacedCatalog($base, $delta);
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

    /**
     * The current filters pick the car by ids (refModelId, refGenerationIds …); a new make,
     * model or generation in the words replaces that part of the pick and what lies under it.
     * "а5" over an A5 already picked keeps its generations.
     */
    private function dropReplacedCatalog(array $base, array $delta): array
    {
        $levels = [
            'make'       => ['make', 'refMakeId', 'model', 'refModelId', 'generation', 'refGenerationIds', 'badge', 'refModificationIds', 'trim', 'refComplectationIds'],
            'model'      => ['model', 'refModelId', 'generation', 'refGenerationIds', 'badge', 'refModificationIds', 'trim', 'refComplectationIds'],
            'generation' => ['generation', 'refGenerationIds', 'badge', 'refModificationIds'],
            'badge'      => ['badge', 'refModificationIds'],
            'trim'       => ['trim', 'refComplectationIds'],
        ];
        $names = app(CatalogNames::class);
        foreach ($levels as $field => $drop) {
            $said = trim((string) ($delta[$field] ?? ''));
            if ($said === '') {
                continue;
            }
            $same = match ($field) {
                'make'  => mb_strtolower($said) === mb_strtolower(trim((string) ($base['make'] ?? '')))
                    || (($base['refMakeId'] ?? 0) > 0 && $names->make($said) === (int) $base['refMakeId']),
                'model' => mb_strtolower($said) === mb_strtolower(trim((string) ($base['model'] ?? '')))
                    || ($base['refModelId'] ?? 0) > 0
                    && ($names->model(($base['refMakeId'] ?? 0) ?: null, $said)['id'] ?? null) === (int) $base['refModelId'],
                default => false,
            };
            if (!$same) {
                foreach ($drop as $key) {
                    unset($base[$key]);
                }
            }
        }
        return $base;
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

    /** $knownMake: the make already picked, so "а5" alone is that make's A5. */
    private function fallbackParse(string $text, ?string $knownMake = null): ?array
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
        if (!$detectedMake && $knownMake !== null && trim($knownMake) !== '') {
            $detectedMake = array_values(array_filter(array_keys($makes),
                fn ($m) => mb_strtolower($m) === mb_strtolower(trim($knownMake))))[0] ?? null;
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

            // English model names from JSON; "ауди а5" typed in Cyrillic is A5
            if (empty($result['model'])) {
                $latin = CatalogNames::latin($text);
                foreach (($makes[$detectedMake] ?? []) as $model) {
                    if (mb_stripos($latin, mb_strtolower($model)) !== false) {
                        $result['model'] = $model;
                        break;
                    }
                }
            }

            // Numeric series (BMW 7 → 7 Series), after the names: "х5" is X5, not 5 Series
            if (empty($result['model']) && isset($numericSeries[$makeLower])) {
                foreach ($numericSeries[$makeLower] as $digit => $series) {
                    if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote((string) $digit, '/') . '(?![\p{L}\p{N}])/u', $text)) {
                        $result['model'] = $series;
                        break;
                    }
                }
            }
        } elseif ($found = $this->modelWithoutMake($text, $makes)) {
            // "соната дизель", "камри гибрид", "x5 40d": the model names its make
            [$result['make'], $result['model']] = $found;
        }

        // ── Year: "2018-2021", "с 2018 по 2021", "от 2018 до 2021", "не старше 2019", "2020 года" ──
        $year = '((?:19|20)\d{2})(?![\d.,]|\s*(?:км|km|тыс|млн|\$|₩|вон))';
        if (preg_match("/(?<!\d)$year\s*(?:г\.?|года?)?\s*(?:-|–|—|до|по)\s*$year/u", $text, $m)) {
            [$result['yearFrom'], $result['yearTo']] = [(int) $m[1], (int) $m[2]];
        } else {
            if (preg_match("/(?:от|from|с|не\s+старше|после|(?<!не\s)новее)\s*$year/u", $text, $m)) {
                $result['yearFrom'] = (int) $m[1];
            }
            if (preg_match("/(?:до|по|to|не\s+новее|(?<!не\s)старше)\s*$year/u", $text, $m)) {
                $result['yearTo'] = (int) $m[1];
            }
            if (!isset($result['yearFrom']) && !isset($result['yearTo'])
                && preg_match("/(?<!\d)$year\s*(?:г\.?|года?|year)(?!\p{L})/u", $text, $m)) {
                $result['yearFrom'] = $result['yearTo'] = (int) $m[1];
            }
        }

        // ── Price: "до 30 млн", "от 20 до 30 млн", "20-30 млн вон", "цена до 25000000", "до 20000$" ──
        [$lo, $hi] = $this->rangeIn($text, 'цен\w*|стоим\w*|бюджет\w*|price',
            fn (string $unit, string $cur, string $after) => preg_match('/^млн|^миллион/u', $unit) || $cur !== ''
                || preg_match('/^\s*(?:вон|krw|₩|\$|долл|usd)/u', $after),
            function (string $num, string $unit, string $cur, bool $led): ?int {
                $v = $this->amount($num, $unit);
                if (preg_match('/\$|долл|usd/u', $cur)) {
                    $v *= 1300;                     // the prompt's rate: 1$ ≈ 1300₩
                } elseif ($unit === '' && $led && $v > 0 && $v < 1000) {
                    $v *= 1_000_000;                // "цена до 30" is 30 млн вон
                }
                return $v >= 100_000 ? (int) round($v) : null;
            });
        if ($lo !== null) $result['priceMin'] = $lo;
        if ($hi !== null) $result['priceMax'] = $hi;

        // ── Mileage: "пробег до 100 тыс", "пробег 50-100 тыс км", "до 80000 км" ──
        [$lo, $hi] = $this->rangeIn($text, 'пробег\w*|mileage|пробегом',
            fn (string $unit, string $cur, string $after) => $cur === '' && preg_match('/^\s*(?:км|km|т\.?\s*км)/u', $after),
            function (string $num, string $unit, string $cur, bool $led): ?int {
                $v = $this->amount($num, $unit);
                if ($unit === '' && $v > 0 && $v < 1000) {
                    $v *= 1000;                     // "пробег до 100" is 100 тыс км
                }
                return $v > 0 && $v < 2_000_000 ? (int) round($v) : null;
            });
        if ($lo !== null) $result['mileageMin'] = $lo;
        if ($hi !== null) $result['mileageMax'] = $hi;

        // ── Fuel, gearbox, drive, body: the first phrase of each list that the text has ──
        $lists = [
            'fuelTypes' => [
                '/плагин|plug.?in|подключаем\w*\s+гибрид/u' => 'plugin_hybrid',
                '/гибрид|hybrid/u' => 'hybrid',
                '/электр|electric|\bev\b|электромобил/u' => 'electric',
                '/дизел|diesel|\bдиз\b/u' => 'diesel',
                '/бенз|gasoline|petrol/u' => 'gasoline',
                '/\bгаз\b|газов|\blpg\b|\blpi\b|пропан/u' => 'lpg',
                '/водород|hydrogen/u' => 'hydrogen',
            ],
            'transmissions' => [
                '/вариатор|\bcvt\b/u' => 'cvt',
                '/робот|\bdct\b|преселектив/u' => 'dct',
                '/механ|мкпп|manual|ручн\w*\s+коробк/u' => 'manual',
                '/автомат|акпп|automatic/u' => 'automatic',
            ],
            'driveTypes' => [
                // not "quattro"/"xDrive": that names the modification, and many lots state no drive
                '/полн\w*\s*привод|полноприв|\b4x4\b|\b4wd\b|\bawd\b/u' => 'awd',
                '/передн\w*\s*привод|переднеприв|\bfwd\b|\b2wd\b/u' => 'fwd',
                '/задн\w*\s*привод|заднеприв|\brwd\b/u' => 'rwd',
            ],
            'bodyTypes' => [
                '/седан|sedan/u' => 'sedan',
                '/кроссовер|внедорожник|паркетник|джип|\bsuv\b/u' => 'suv',
                '/хэтчбек|хетчбек|хечбек|hatchback/u' => 'hatchback',
                '/универсал|wagon|touring|avant/u' => 'wagon',
                '/купе|coupe/u' => 'coupe',
                '/кабриолет|convertible|cabrio/u' => 'convertible',
                '/минив[эе]н|minivan|\bвэн\b|\bvan\b/u' => 'van',
                '/пикап|pickup/u' => 'pickup',
            ],
        ];
        foreach ($lists as $field => $patterns) {
            foreach ($patterns as $pattern => $value) {
                if (preg_match($pattern, $text)) {
                    $result[$field] = [$value];
                    break;
                }
            }
        }

        // ── Engine volume: "2.0", "2,5 л", "2.0-3.0", "от 2 до 3 литров" ──
        $vol = '(\d(?:[.,]\d)?)(?![\d])(?!\s*(?:млн|миллион|тыс|k\b|к\b|\$|₩|вон|км|km))';
        if (preg_match("/(?:двигател\w*|объ[её]м\w*|мотор\w*|engine)?\s*(?:от\s*)?(?<![\d.,])(\d[.,]\d)\s*(?:л|l)?\s*(?:-|–|—|до)\s*(\d[.,]\d)(?!\d|\s*(?:млн|миллион|тыс|\\$|₩|вон))/u", $text, $m)
            || preg_match("/(?:двигател\w*|объ[её]м\w*|мотор\w*|engine)\s*(?:от\s*)?$vol\s*(?:л|l|литр\w*)?\s*(?:-|–|—|до)\s*$vol/u", $text, $m)
            || preg_match("/(?:от\s*)?(?<![\d.,])$vol\s*(?:-|–|—|до)\s*$vol\s*(?:л|l|литр\w*)(?!\p{L})/u", $text, $m)) {
            [$lo, $hi] = [(float) str_replace(',', '.', $m[1]), (float) str_replace(',', '.', $m[2])];
            if ($lo >= 0.5 && $hi <= 9.0 && $lo <= $hi) {
                [$result['engineMin'], $result['engineMax']] = [$lo, $hi];
            }
        } elseif (preg_match('/(?<![\d.,])(\d[.,]\d)(?![\d])(?!\s*(?:млн|миллион|тыс|k\b|к\b|\$|₩|вон|км|km))/u', $text, $m)
            || preg_match('/(?:двигател\w*|объ[её]м\w*|мотор\w*|engine)[\s:]+(\d)(?![\d.,])/u', $text, $m)) {
            $v = (float) str_replace(',', '.', $m[1]);
            if ($v >= 0.5 && $v <= 9.0) {
                $result['engineMin'] = $result['engineMax'] = $v;
            }
        }

        if (preg_match('/(?:страхов|insurance).*?(?:до|max|<)\s*(\d+)/u', $text, $m)) {
            $result['insuranceCountMax'] = (int) $m[1];
        }
        if (preg_match('/(?:без\s*страхов|0\s*страхов)/u', $text)) {
            $result['insuranceCountMax'] = 0;
        }

        if (preg_match('/(\d+|один|одн\w*|перв\w*)\s*(?:владел|хозя|owner)/u', $text, $m)) {
            $result['ownersCountMax'] = ctype_digit($m[1]) ? (int) $m[1] : 1;
        } elseif (preg_match('/(?:владел\w*|хозя\w*)\s*(?:не\s+более|до|max)?\s*(\d+)/u', $text, $m)) {
            $result['ownersCountMax'] = (int) $m[1];
        }

        if (preg_match('/без\s*(?:дтп|авар|удар|accident)|не\s*бит|небит|не\s+был\w*\s+в\s+дтп|no\s+accident|accident.?free/ui', $text)) {
            $result['hasAccident'] = false;
        } elseif (preg_match('/(?:с|после|был\w*\s+в)\s*дтп|были\s*авар|(?<!не\s)(?<!не)бит(?:ая|ый|ые)|has.*accident/ui', $text)) {
            $result['hasAccident'] = true;
        }

        if (preg_match('/без\s*(?:затопл|утопл|flood)|не\s*(?:утоплен|затоплен|топлен)/ui', $text)) {
            $result['floodHistory'] = false;
        }
        if (preg_match('/без\s*тотал|не\s*тотал|не\s+признан\w*\s+тотал/ui', $text)) {
            $result['totalLossHistory'] = false;
        }

        if (preg_match('/без\s*(?:залог|обременен|lien)/ui', $text)) {
            $result['lienStatuses'] = ['clean'];
        }

        $colorMap = [
            '/бел(?:ый|ая|ое|ые|ого)|white/u' => 'White', '/ч[её]рн|black/u' => 'Black',
            '/сер(?:ый|ая|ое|ые|ого)|графит|gr[ae]y/u' => 'Gray', '/серебр|silver/u' => 'Silver',
            '/красн|бордов|red\b/u' => 'Red', '/син(?:ий|яя|ее|ие|его)|голуб|blue/u' => 'Blue',
            '/зел[её]н|green/u' => 'Green', '/коричн|brown/u' => 'Brown', '/ж[её]лт|yellow/u' => 'Yellow',
            '/золот|gold/u' => 'Gold', '/оранж|orange/u' => 'Orange', '/фиолет|пурпур|purple/u' => 'Purple',
        ];
        $colors = [];
        foreach ($colorMap as $pattern => $value) {
            if (preg_match($pattern, $text)) {
                $colors[] = $value;
            }
        }
        if ($colors) {
            $result['colors'] = $colors;
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

        if ($options = $this->optionsIn($text)) {
            $result['options'] = $options;
        }

        return empty($result) ? null : $result;
    }

    /**
     * A model named with no make: Russian names of the popular ones, then the catalog's Latin
     * names as whole words ("x5", "camry"). Only a name that one make has.
     *
     * @param array<string, list<string>> $makes
     * @return array{0: string, 1: string}|null
     */
    private function modelWithoutMake(string $text, array $makes): ?array
    {
        $aliases = [
            'соната' => ['Hyundai', 'Sonata'], 'грандеур' => ['Hyundai', 'Grandeur'], 'грандер' => ['Hyundai', 'Grandeur'],
            'гранжер' => ['Hyundai', 'Grandeur'], 'авант' => ['Hyundai', 'Avante'], 'элантр' => ['Hyundai', 'Avante'],
            'туссан' => ['Hyundai', 'Tucson'], 'туксон' => ['Hyundai', 'Tucson'], 'санта фе' => ['Hyundai', 'Santa Fe'],
            'санта-фе' => ['Hyundai', 'Santa Fe'], 'сантафе' => ['Hyundai', 'Santa Fe'], 'палисад' => ['Hyundai', 'Palisade'],
            'старекс' => ['Hyundai', 'Starex'], 'стария' => ['Hyundai', 'Staria'], 'кона' => ['Hyundai', 'Kona'],
            'спортейдж' => ['Kia', 'Sportage'], 'спортэйдж' => ['Kia', 'Sportage'], 'соренто' => ['Kia', 'Sorento'],
            'карнивал' => ['Kia', 'Carnival'], 'морнинг' => ['Kia', 'Morning'], 'мохав' => ['Kia', 'Mohave'],
            'селтос' => ['Kia', 'Seltos'], 'стингер' => ['Kia', 'Stinger'], 'камри' => ['Toyota', 'Camry'],
            'королл' => ['Toyota', 'Corolla'], 'рав4' => ['Toyota', 'RAV4'], 'рав 4' => ['Toyota', 'RAV4'],
            'крузак' => ['Toyota', 'Land Cruiser'], 'ленд крузер' => ['Toyota', 'Land Cruiser'],
            'кайен' => ['Porsche', 'Cayenne'], 'макан' => ['Porsche', 'Macan'], 'панамер' => ['Porsche', 'Panamera'],
            'тигуан' => ['Volkswagen', 'Tiguan'], 'пассат' => ['Volkswagen', 'Passat'], 'гольф' => ['Volkswagen', 'Golf'],
            'туарег' => ['Volkswagen', 'Touareg'], 'аркана' => ['Renault Korea', 'Arkana'],
        ];
        foreach ($aliases as $alias => [$make, $model]) {
            if (mb_strpos($text, $alias) !== false && isset($makes[$make]) && in_array($model, $makes[$make], true)) {
                return [$make, $model];
            }
        }
        $latin = CatalogNames::latin($text);
        $found = [];
        foreach ($makes as $make => $models) {
            foreach ($models as $model) {
                $name = mb_strtolower($model);
                // a short word ("ray", "one") is too common to name a car alone
                if ((mb_strlen($name) >= 4 || preg_match('/\d/', $name))
                    && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/u', $latin)) {
                    $found[$make . "\0" . $model] = [$make, $model];
                }
            }
        }
        // the longest name: "grand carnival" over "carnival"
        uasort($found, fn ($a, $b) => mb_strlen($b[1]) <=> mb_strlen($a[1]));
        $first = reset($found);
        return $first !== false && count(array_filter($found, fn ($f) => mb_strlen($f[1]) === mb_strlen($first[1]))) === 1 ? $first : null;
    }

    /** "2,5" + "млн" → 2 500 000; "100" + "тыс" → 100 000; "100 000" → 100000. */
    private function amount(string $num, string $unit): float
    {
        $v = (float) str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $num);
        return match (true) {
            (bool) preg_match('/^(?:млн|миллион)/u', $unit) => $v * 1_000_000,
            (bool) preg_match('/^(?:тыс|т\.?$|k$|к$)/u', $unit) => $v * 1000,
            default => $v,
        };
    }

    /**
     * [min, max] of one range in the text: "от 20 до 30 млн", "20-30 млн", "до 30 млн", "от 2 млн".
     * $lead: the words that name it before the numbers ("цена", "пробег"). Without them, $isIt
     * decides by the unit, the currency or the word right after ("20-30 млн", "до 100 тыс км").
     * $value turns a number into the filter's value, null when it is not one.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function rangeIn(string $text, string $lead, callable $isIt, callable $value): array
    {
        $n = '(\d{1,3}(?:[ \x{00A0}]\d{3})+(?!\d)|\d+(?:[.,]\d+)?)\s*(млн\w*|миллион\w*|тыс\w*|т\.|k(?![a-z])|к(?!\p{L}))?\.?\s*(\$|долл\w*|usd|₩|вон\w*|krw)?';
        $after = '(?=(\s*\S{0,6}))';
        $range = "(?:от\s*)?$n\s*(?:-|–|—|до|по)\s*$n$after";
        foreach (["/(?:$lead)\D{0,12}?$range/u" => true, "/(?<![\d.,])$range/u" => false] as $re => $led) {
            preg_match_all($re, $text, $all, PREG_SET_ORDER);
            foreach ($all as $m) {
                $m = array_pad($m, 8, '');
                // "20-30 млн": the first number takes the second's unit and currency
                [$unit, $cur] = [$m[5] ?: $m[2], $m[6] ?: $m[3]];
                // "не старше 2020 до 20000$": a year is not the price's low end
                $isYear = $m[2] === '' && $m[3] === '' && preg_match('/^(?:19|20)\d{2}$/', $m[1]);
                if (!$isYear && ($led || $isIt($unit, $cur, $m[7]))) {
                    $lo = $value($m[1], $m[2] ?: $unit, $cur, $led);
                    $hi = $value($m[4], $unit, $cur, $led);
                    if ($lo !== null && $hi !== null && $lo <= $hi) {
                        return [$lo, $hi];
                    }
                }
            }
        }
        $out = [null, null];
        $sides = [0 => 'от|from|min|не\s+меньше|не\s+менее|больше|более|свыше', 1 => 'до|to|max|under|не\s+больше|не\s+более|меньше|менее|дешевле'];
        foreach ($sides as $side => $words) {
            foreach (["/(?:$lead)\D{0,12}?(?<!\p{L})(?:$words)\s*$n$after/u" => true, "/(?<!\p{L})(?:$words)\s*$n$after/u" => false] as $re => $led) {
                preg_match_all($re, $text, $all, PREG_SET_ORDER);
                foreach ($all as $m) {
                    $m = array_pad($m, 5, '');
                    if (($led || $isIt($m[2], $m[3], $m[4])) && ($v = $value($m[1], $m[2], $m[3], $led)) !== null) {
                        $out[$side] = $v;
                        continue 3;
                    }
                }
            }
        }
        // "пробег 50000", "цена 25 млн": one number after the word is the most
        if ($out === [null, null] && preg_match("/(?:$lead)(?!\p{L})\s*:?\s*$n/u", $text, $m)) {
            $m = array_pad($m, 4, '');
            $out[1] = $value($m[1], $m[2], $m[3], true);
        }
        return $out;
    }

    /**
     * Encar option codes named in the text ("панорамный люк" → 010). The AI gets the whole
     * option list in its prompt; this is the same for the fallback and for what the AI missed.
     *
     * @return list<string>
     */
    private function optionsIn(string $text): array
    {
        $text = mb_strtolower($text);
        $patterns = [
            '010' => '/люк|пан?орам|понорам|понарам|sunroof|panoram/u',
            '014' => '/кож[аеуи]|кожан|leather/u',
            '005' => '/навигац|навигатор|navigation/u',
            '082' => '/подогрев\w*\s+рул|руль\s+с\s+подогрев|heated\s+steering/u',
            '063' => '/подогрев\w*\s+задн/u',
            '022' => '/подогрев\w*+(?!\s+(?:рул|задн))|heated\s+seat/u',
            '090' => '/вентиляц\w*\s+задн/u',
            '034' => '/вентиляц|ventilated/u',
            '087' => '/камер\w*\s*360|360\s*(?:камер|обзор)|кругов\w*\s+обзор|around\s*view/u',
            '058' => '/камер\w*\s+задн|задн\w*\s+камер|rear\s+camera/u',
            '079' => '/адаптивн\w*\s+круиз|\bacc\b|adaptive\s+cruise/u',
            '068' => '/круиз|cruise/u',
            '095' => '/\bhud\b|проекц|head.?up/u',
            '057' => '/смарт.?ключ|бесключ|smart\s*key/u',
            '032' => '/парктроник|датчик\w*\s+парков|parking\s+sensor/u',
            '059' => '/электро\s*багажник|электропривод\w*\s+багажник|power\s+trunk/u',
            '091' => '/массаж|massage/u',
            '062' => '/рейлинг|roof\s+rail/u',
            '086' => '/мертв\w*\s+зон|blind\s+spot/u',
            '094' => '/электроручник|электронн\w*\s+ручник|\bepb\b/u',
            '096' => '/bluetooth|блютуз/u',
        ];
        $codes = [];
        foreach ($patterns as $code => $pattern) {
            if (preg_match($pattern, $text)) {
                $codes[] = (string) $code;
            }
        }
        // "адаптивный круиз" is 079, not also plain cruise control
        if (in_array('079', $codes, true)) {
            $codes = array_values(array_diff($codes, ['068']));
        }
        return $codes;
    }

    /** Only codes of the option catalog: the AI may copy a placeholder or invent one. */
    private function knownOptions(array $codes): array
    {
        try {
            $known = EncarOptionCatalog::getCached()->pluck('code')->map(fn ($c) => (string) $c)->all();
        } catch (\Throwable) {
            return array_values(array_map('strval', $codes));
        }
        return $known === [] ? array_values(array_map('strval', $codes))
            : array_values(array_intersect(array_map('strval', $codes), $known));
    }

    /**
     * What the AI left out but the words name for sure: the model ("ауди А5" parsed as just
     * Audi) and options ("панорамный люк"). Never overrides what the AI said.
     */
    private function fillGaps(array $json, string $text, ?string $knownMake = null): array
    {
        $fallback = $this->fallbackParse($text, trim((string) ($json['make'] ?? '')) ?: $knownMake) ?? [];
        $make = trim((string) ($json['make'] ?? ''));
        if (trim((string) ($json['model'] ?? '')) === '' && !empty($fallback['model'])
            && ($make === '' || mb_strtolower($make) === mb_strtolower((string) ($fallback['make'] ?? '')))) {
            $json['make']  = $fallback['make'];
            $json['model'] = $fallback['model'];
        }
        $options = $this->knownOptions(array_merge((array) ($json['options'] ?? []), $fallback['options'] ?? []));
        if ($options !== []) {
            $json['options'] = array_values(array_unique($options));
        } else {
            unset($json['options']);
        }
        return $json;
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
14. Числа 1.6, 2.0, 2.5, 3.0 и т.д. рядом с маркой/моделью — объём двигателя → engineMin и engineMax; "2.0-3.0"/"от 2 до 3 литров" → engineMin: 2.0, engineMax: 3.0
15. Диапазоны в любой форме: "от X до Y", "X-Y", "X–Y", "с X по Y", "между X и Y" → Min и Max; "от X"/"не меньше X"/"свыше X" → только Min; "до X"/"не больше X"/"не дороже X"/"дешевле X" → только Max
16. Пробег: "пробег до 100 тыс"/"до 100к км" → mileageMax: 100000; "50-100 тыс км" → mileageMin: 50000, mileageMax: 100000; "небольшой пробег"/"малый пробег" → mileageMax: 60000. "тыс"/"т.км"/"к" = ×1000
17. Цена в ₩ (KRW). "млн" = ×1 000 000, "тыс" = ×1000. 1$ ≈ 1300₩. "до 15000$" → priceMax: 19500000; "до 20 млн" → priceMax: 20000000; "от 20 до 30 млн"/"20-30 млн" → priceMin: 20000000, priceMax: 30000000; "цена до 25" без единиц — это миллионы вон
18. ДТП в любой формулировке: "без ДТП"/"без аварий"/"не битая"/"небитый"/"не был в ДТП"/"чистая история" → hasAccident: false; "с ДТП"/"битая"/"после аварии" → hasAccident: true
19. "без залога"/"без обременений"/"без кредита" → lienStatuses: ["clean"]; "без ареста" → seizureStatuses: ["clean"]
20. "1 владелец"/"один хозяин"/"первый владелец" → ownersCountMax: 1; "до 2 владельцев" → ownersCountMax: 2
21. "без страховых"/"0 страховых"/"без страховых случаев" → insuranceCountMax: 0; "не больше 2 страховых" → insuranceCountMax: 2
22. "не затоплена"/"без утоплений"/"не утопленник" → floodHistory: false; "не тотал"/"без тотала" → totalLossHistory: false
23. Одно число без "от/до": год → yearFrom и yearTo равны; объём → engineMin и engineMax равны; пробег и цена → только Max ("пробег 50000" → mileageMax: 50000, "за 25 млн" → priceMax: 25000000). "не старше 2019"/"от 2019" → yearFrom: 2019; "2018-2021" → yearFrom: 2018, yearTo: 2021
24. Комплектация/trim: комплектация латиницей, как в каталоге, если пользователь её назвал (премиум → Premium, эс лайн → S line, м спорт → M Sport). Код двигателя модификации ("45 TFSI quattro", "530e", "2.0T", "40d") тоже клади в trim. Примеры комплектаций: {$trimExamples}
25. Поколение/generation: код поколения или кузова, только если пользователь его назвал: "G30", "W213", "DN8", "F5" → generation: "G30". Не выводи поколение из года.
26. Марку и модель пиши ТОЧНО как в списке доступных (см. ниже). Русские и кириллические варианты маппи: мерседес→Mercedes-Benz, бмв→BMW, хендай/хёндай→Hyundai, тойота→Toyota, порше→Porsche, ауди→Audi; модель кириллицей или транслитом — латиницей из списка: "А5"→"A5", "Х5"→"X5", "камри"→"Camry", "соната"→"Sonata", "е класс"→"E-Class", "5 серия"/"пятёрка"→"5 Series". Если модели нет в списке, всё равно верни её латиницей
30. Цвет colors[] — только из: "White", "Black", "Gray", "Silver", "Blue", "Red", "Green", "Brown", "Yellow", "Gold", "Orange", "Purple"; можно несколько ("белая или чёрная" → ["White", "Black"])
31. Привод driveTypes: "awd" (полный, 4WD, AWD), "fwd" (передний), "rwd" (задний) — только если привод назван словами; quattro/xDrive/4MATIC — часть модификации, клади в trim, не в driveTypes. Кузов bodyTypes: "sedan", "suv" (кроссовер, внедорожник, джип), "hatchback", "wagon" (универсал), "coupe", "convertible", "van" (минивэн), "pickup". Топливо fuelTypes: "gasoline", "diesel", "hybrid", "plugin_hybrid", "electric", "lpg" (газ), "hydrogen". КПП transmissions: "automatic", "manual", "cvt", "dct"
32. Опции (люк, панорама, кожа, подогревы, вентиляция, камеры, круиз, HUD, навигация, смарт-ключ и т.д.) — options[] с кодами из списка опций; несколько опций → несколько кодов
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
→ {"make": "BMW", "model": "5 Series", "generation": "G30", "fuelTypes": ["diesel"], "mileageMax": 100000}

User: "ауди А5 понарамный люк 2018-2021 от 20 до 30 млн пробег до 100 тыс не битая"
→ {"make": "Audi", "model": "A5", "options": ["010"], "yearFrom": 2018, "yearTo": 2021, "priceMin": 20000000, "priceMax": 30000000, "mileageMax": 100000, "hasAccident": false}

User: "x5 40d xdrive m sport чёрный кожа вентиляция"
→ {"make": "BMW", "model": "X5", "trim": "40d M Sport", "colors": ["Black"], "options": ["014", "034"]}

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

    /** The reference catalog's complectations with the most active lots, as the AI must name them. */
    private function buildTrimExamplesForPrompt(): string
    {
        return Cache::remember('chat_search_catalog_complectations', 600, function () {
            try {
                $names = DB::table('ref_variants as v')
                    ->join('ref_complectations as c', 'c.id', '=', 'v.complectation_id')
                    ->where('v.lots', '>', 0)
                    ->groupBy('c.name')
                    ->orderByRaw('SUM(v.lots) DESC')
                    ->limit(50)
                    ->pluck('c.name')->all();
                return $names ? '"' . implode('", "', $names) . '"' : '"Prestige", "Premium", "Noblesse", "Signature"';
            } catch (\Throwable) {
                return '"Prestige", "Premium", "Noblesse", "Signature"';
            }
        });
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

                $lines[] = 'Пример: пользователь "с кожаным салоном и (панорамным) люком" → options: ["014", "010"]';

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
        foreach ($makes as $make => $models) {
            // every model: a model left out of the list was dropped by the AI ("ауди а5" → just Audi)
            $lines[] = "- {$make}: " . implode(', ', $models);
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

        // the reference catalog's makes and models that have active lots, most lots first
        self::$makesModels = Cache::remember('chat_search_catalog_makes_models', 600, function () {
            try {
                $rows = DB::table('ref_variants as v')
                    ->join('ref_makes as k', 'k.id', '=', 'v.make_id')
                    ->leftJoin('ref_models as m', 'm.id', '=', 'v.model_id')
                    ->where('v.lots', '>', 0)
                    ->groupBy('k.name', 'm.name')
                    ->selectRaw('k.name AS make, m.name AS model, SUM(v.lots) AS n')
                    ->get();

                $byMake = $lots = [];
                foreach ($rows as $row) {
                    $lots[$row->make] = ($lots[$row->make] ?? 0) + (int) $row->n;
                    $byMake[$row->make] ??= [];
                    if ($row->model !== null) {
                        $byMake[$row->make][$row->model] = (int) $row->n;
                    }
                }
                arsort($lots);
                $out = [];
                foreach (array_keys($lots) as $make) {
                    arsort($byMake[$make]);
                    $out[$make] = array_keys($byMake[$make]);
                }
                return $out;
            } catch (\Throwable) {
                return [];
            }
        });

        return self::$makesModels;
    }
}
