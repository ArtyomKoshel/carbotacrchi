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
            $response = Http::timeout(20)
                ->withHeaders(self::HEADERS)
                ->get(self::ENCAR_FILTER_URL, [
                    'count'   => 'true',
                    'q'       => '(And.Hidden.N._.CarType.A.)',
                    'sr'      => '|ModifiedDate|0|1',   // fetch only 1 result to minimize payload
                    'filters' => 'OPTION',
                ]);

            if (!$response->successful()) {
                Log::warning('[ImportEncarOptions] HTTP ' . $response->status());
                return null;
            }

            $body = $response->json();

            // Find the "Option" filter group
            $filters = $body['Filters'] ?? [];
            $optionFilter = collect($filters)
                ->first(fn($f) => strtolower($f['FilterType'] ?? '') === 'option');

            if (!$optionFilter) {
                Log::warning('[ImportEncarOptions] "Option" filter group not found in response', [
                    'filter_types' => collect($filters)->pluck('FilterType')->all(),
                ]);
                return null;
            }

            $result = [];
            foreach ($optionFilter['Items'] ?? [] as $item) {
                $meta = $item['Metadata'] ?? [];
                $code = $meta['Code'] ?? ($item['Value'] ?? null);
                $name = $meta['Name'] ?? null;

                if (!$code || !$name) {
                    continue;
                }

                $result[] = [
                    'code'     => (string) $code,
                    'name_kr'  => (string) $name,
                    'icon_url' => $meta['IconUrl'] ?? null,
                ];
            }

            return $result;

        } catch (\Throwable $e) {
            Log::error('[ImportEncarOptions] ' . $e->getMessage());
            return null;
        }
    }
}
