<?php

namespace App\Console\Commands;

use App\Models\EncarOptionCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Загружает каталог опций из публичного фильтр-API Encar и делает upsert в encar_option_catalog.
 *
 * Запуск:
 *   php artisan options:import-encar
 *   php artisan options:import-encar --dry-run       # показать что было бы импортировано
 *   php artisan options:import-encar --no-flush      # не сбрасывать Redis-кэш после импорта
 */
class ImportEncarOptions extends Command
{
    protected $signature = 'options:import-encar
        {--dry-run   : Show parsed options without saving to DB}
        {--no-flush  : Skip flushing Redis cache after import}';

    protected $description = 'Fetch options catalog from Encar filter API and upsert into encar_option_catalog';

    /**
     * Encar mobile search endpoint — returns Filters.Option items with
     * code, Korean name, and optional icon URL.
     */
    private const ENCAR_FILTER_URL = 'https://api.encar.com/search/car/list/mobile';
    private const ENCAR_BATCH_DETAILS_URL = 'https://api.encar.com/v1/readside/vehicles';
    private const DETAIL_INCLUDE = 'SPEC,ADVERTISEMENT,PHOTOS,CATEGORY,MANAGE,CONTACT,CONDITION,OPTIONS,VIEW';

    private const HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36',
        'Accept'     => 'application/json, text/javascript, */*; q=0.01',
        'Referer'    => 'https://m.encar.com/',
        'Origin'     => 'https://m.encar.com',
    ];

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $noFlush  = (bool) $this->option('no-flush');

        $this->info('Fetching options catalog from Encar API...');

        $items = $this->fetchOptionItems();

        if ($items === null) {
            $this->error('Failed to fetch options from Encar API. Check logs for details.');
            return 1;
        }

        if (empty($items)) {
            $this->warn('Encar API returned 0 option items. Nothing to import.');
            return 0;
        }

        $this->info("Found " . count($items) . " option items.");

        if ($isDryRun) {
            $this->table(
                ['code', 'name_kr', 'icon_url'],
                array_map(fn($i) => [
                    $i['code'],
                    $i['name_kr'],
                    $i['icon_url'] ?? '—',
                ], $items)
            );
            return 0;
        }

        $upserted = 0;
        foreach ($items as $order => $item) {
            EncarOptionCatalog::upsert(
                [
                    'code'       => $item['code'],
                    'name_kr'    => $item['name_kr'],
                    'icon_url'   => $item['icon_url'] ?? null,
                    'sort_order' => $order,
                ],
                uniqueBy: ['code'],
                update:   ['name_kr', 'icon_url', 'sort_order'],
            );
            $upserted++;
        }

        $this->info("Upserted {$upserted} option records.");

        if (!$noFlush) {
            EncarOptionCatalog::flushCache();
            $this->info('Redis cache flushed.');
        }

        return 0;
    }

    /**
     * Fetch the Option filter items from Encar's search API.
     *
     * The API returns a Filters array; we extract the "Option" group and map each
     * item to ['code', 'name_kr', 'icon_url'].
     *
     * Example response item:
     *   {
     *     "Count": 5432,
     *     "IsSelected": false,
     *     "Metadata": {"Code":"001","Name":"선루프","IconUrl":"https://..."},
     *     "Value": "001"
     *   }
     *
     * @return array[]|null  null on HTTP / parse error
     */
    private function fetchOptionItems(): ?array
    {
        try {
            $requestVariants = [
                [
                    'count'   => 'true',
                    'q'       => '(And.Hidden.N._.CarType.A.)',
                    'sr'      => '|ModifiedDate|0|1',
                    'filters' => 'OPTION',
                ],
                [
                    'count' => 'true',
                    'q'     => '(And.Hidden.N._.CarType.A.)',
                    'sr'    => '|ModifiedDate|0|1',
                ],
            ];

            foreach ($requestVariants as $idx => $query) {
                $response = Http::timeout(20)
                    ->withHeaders(self::HEADERS)
                    ->get(self::ENCAR_FILTER_URL, $query);

                if (!$response->successful()) {
                    Log::warning('[ImportEncarOptions] HTTP ' . $response->status(), [
                        'variant' => $idx + 1,
                        'query'   => $query,
                    ]);
                    continue;
                }

                $body = $response->json();
                if (!is_array($body)) {
                    Log::warning('[ImportEncarOptions] Non-array JSON response', [
                        'variant' => $idx + 1,
                        'query'   => $query,
                    ]);
                    continue;
                }

                $items = $this->parseOptionItemsFromPayload($body);
                if (!empty($items)) {
                    return $items;
                }

                $filters = $this->extractFilters($body);
                Log::warning('[ImportEncarOptions] "Option" filter group not found in response', [
                    'variant'        => $idx + 1,
                    'query'          => $query,
                    'top_level_keys' => array_keys($body),
                    'filter_types'   => collect($filters)->map(function ($f) {
                        if (!is_array($f)) {
                            return null;
                        }
                        return $f['FilterType'] ?? $f['Type'] ?? $f['Key'] ?? null;
                    })->filter()->values()->all(),
                ]);
            }

            $fallbackItems = $this->fetchOptionItemsFromVehicleDetails();
            if (!empty($fallbackItems)) {
                Log::info('[ImportEncarOptions] Loaded option catalog via vehicle details fallback', [
                    'count' => count($fallbackItems),
                ]);
                return $fallbackItems;
            }

            return null;

        } catch (\Throwable $e) {
            Log::error('[ImportEncarOptions] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract option items from known Encar payload shapes.
     *
     * @return array<int, array{code:string,name_kr:string,icon_url:?string}>
     */
    private function parseOptionItemsFromPayload(array $body): array
    {
        $filters = $this->extractFilters($body);
        if ($filters === []) {
            return [];
        }

        $optionFilter = collect($filters)->first(function ($f) {
            if (!is_array($f)) {
                return false;
            }

            $type = mb_strtolower((string) ($f['FilterType'] ?? $f['Type'] ?? $f['Key'] ?? ''));
            $name = mb_strtolower((string) ($f['Name'] ?? $f['DisplayName'] ?? ''));

            return in_array($type, ['option', 'options'], true)
                || in_array($name, ['option', 'options', '옵션'], true);
        });

        if (!is_array($optionFilter)) {
            return [];
        }

        $result = [];
        foreach (($optionFilter['Items'] ?? $optionFilter['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $meta = $item['Metadata'] ?? $item['metadata'] ?? [];
            if (!is_array($meta)) {
                $meta = [];
            }

            $code = $meta['Code'] ?? $meta['code'] ?? $item['Value'] ?? $item['value'] ?? null;
            $name = $meta['Name'] ?? $meta['name'] ?? $item['Name'] ?? $item['name'] ?? null;

            if (!$code || !$name) {
                continue;
            }

            $result[] = [
                'code'     => (string) $code,
                'name_kr'  => (string) $name,
                'icon_url' => $meta['IconUrl'] ?? $meta['iconUrl'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, mixed>
     */
    private function extractFilters(array $body): array
    {
        $candidates = [
            $body['Filters'] ?? null,
            $body['filters'] ?? null,
            $body['Data']['Filters'] ?? null,
            $body['Data']['filters'] ?? null,
            $body['Result']['Filters'] ?? null,
            $body['Result']['filters'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * Fallback strategy: fetch recent vehicle IDs from SearchResults,
     * then pull OPTIONS from readside batch-details endpoint.
     *
     * @return array<int, array{code:string,name_kr:string,icon_url:?string}>
     */
    private function fetchOptionItemsFromVehicleDetails(): array
    {
        $searchResponse = Http::timeout(20)
            ->withHeaders(self::HEADERS)
            ->get(self::ENCAR_FILTER_URL, [
                'count' => 'true',
                'q'     => '(And.Hidden.N._.CarType.A.)',
                'sr'    => '|ModifiedDate|0|200',
            ]);

        if (!$searchResponse->successful()) {
            Log::warning('[ImportEncarOptions] Fallback search HTTP ' . $searchResponse->status());
            return [];
        }

        $searchBody = $searchResponse->json();
        if (!is_array($searchBody)) {
            Log::warning('[ImportEncarOptions] Fallback search returned non-array JSON');
            return [];
        }

        $ids = collect($searchBody['SearchResults'] ?? [])
            ->map(fn($row) => $row['Id'] ?? null)
            ->filter(fn($v) => is_scalar($v) && (string) $v !== '')
            ->map(fn($v) => (string) $v)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            Log::warning('[ImportEncarOptions] Fallback search returned no vehicle IDs');
            return [];
        }

        $catalog = [];
        $placeholderNames = 0;

        foreach (array_chunk($ids, 20) as $batchIds) {
            $resp = Http::timeout(25)
                ->withHeaders(self::HEADERS)
                ->get(self::ENCAR_BATCH_DETAILS_URL, [
                    'vehicleIds' => implode(',', $batchIds),
                    'include'    => self::DETAIL_INCLUDE,
                ]);

            if (!$resp->successful()) {
                Log::warning('[ImportEncarOptions] Fallback batch-details HTTP ' . $resp->status(), [
                    'batch_size' => count($batchIds),
                ]);
                continue;
            }

            $body = $resp->json();
            $vehicles = [];
            if (is_array($body)) {
                $vehicles = isset($body['vehicles']) && is_array($body['vehicles'])
                    ? $body['vehicles']
                    : $body;
            }

            foreach ($vehicles as $vehicle) {
                if (!is_array($vehicle)) {
                    continue;
                }

                $options = $vehicle['options'] ?? [];
                if (!is_array($options)) {
                    continue;
                }

                foreach ($options as $group => $items) {
                    if (!is_array($items)) {
                        continue;
                    }

                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        $opt = $item['option'] ?? [];
                        if (!is_array($opt)) {
                            $opt = [];
                        }

                        $code = $opt['id']
                            ?? $opt['code']
                            ?? $item['id']
                            ?? $item['code']
                            ?? null;

                        $name = $opt['name']
                            ?? $opt['krName']
                            ?? $item['name']
                            ?? $item['optionName']
                            ?? null;

                        if ($code === null) {
                            continue;
                        }

                        $code = (string) $code;
                        if ($name === null || $name === '') {
                            $name = '옵션 ' . $code;
                            $placeholderNames++;
                        }

                        if (!isset($catalog[$code])) {
                            $catalog[$code] = [
                                'code'     => $code,
                                'name_kr'  => (string) $name,
                                'icon_url' => $opt['iconUrl'] ?? $item['iconUrl'] ?? null,
                            ];
                        }
                    }
                }
            }
        }

        if ($placeholderNames > 0) {
            Log::warning('[ImportEncarOptions] Fallback imported options with placeholder names (name missing in API payload)', [
                'placeholder_count' => $placeholderNames,
                'total_codes'       => count($catalog),
            ]);
        }

        return array_values($catalog);
    }
}
