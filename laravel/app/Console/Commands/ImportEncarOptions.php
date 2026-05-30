<?php

namespace App\Console\Commands;

use App\Models\EncarOptionCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Загружает официальный каталог опций Encar из публичного endpoint и делает upsert.
 *
 * Endpoint: https://www.encar.com/dc/dc_carsearchlist.do?method=optionlist
 *
 * Ответ содержит:
 *   [0].optionlist   — плоский список { abbreviation (код), name (корейское название), type (категория) }
 *   [0].carOptions   — дерево с subOptions и imagePath для иконок
 *
 * Запуск:
 *   php artisan options:import-encar
 *   php artisan options:import-encar --dry-run
 *   php artisan options:import-encar --no-flush
 */
class ImportEncarOptions extends Command
{
    protected $signature = 'options:import-encar
        {--dry-run   : Show parsed options without saving to DB}
        {--no-flush  : Skip flushing Redis cache after import}';

    protected $description = 'Fetch official options catalog from Encar and upsert into encar_option_catalog';

    private const ENCAR_OPTION_LIST_URL = 'https://www.encar.com/dc/dc_carsearchlist.do';
    private const IMAGE_BASE_URL        = 'https://www.encar.com';

    /** optionTypeCd (type) → our category string */
    private const CATEGORY_MAP = [
        '01' => 'exterior',     // 외관 — внешний вид
        '02' => 'safety',       // 안전 — безопасность
        '03' => 'convenience',  // 편의 — удобство
        '04' => 'interior',     // 내장 — интерьер / сиденья
    ];

    private const HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36',
        'Accept'     => 'application/json, text/javascript, */*; q=0.01',
        'Referer'    => 'https://www.encar.com/',
    ];

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $noFlush  = (bool) $this->option('no-flush');

        $this->info('Fetching options catalog from Encar (dc_carsearchlist.do?method=optionlist)...');

        $items = $this->fetchOptionItems();

        if ($items === null) {
            $this->error('Failed to fetch or parse options. Check logs for details.');
            return 1;
        }

        if (empty($items)) {
            $this->warn('Encar returned 0 option items. Nothing to import.');
            return 0;
        }

        $this->info('Parsed ' . count($items) . ' option entries.');

        if ($isDryRun) {
            $this->table(
                ['code', 'name_kr', 'category', 'icon_url'],
                array_map(fn($i) => [
                    $i['code'],
                    $i['name_kr'],
                    $i['category'] ?? '—',
                    $i['icon_url'] ?? '—',
                ], $items)
            );
            return 0;
        }

        // Batch upsert — one query for all rows.
        // name_ru / name_en are NOT in the update list — they are our translations.
        EncarOptionCatalog::upsert(
            $items,
            uniqueBy: ['code'],
            update:   ['name_kr', 'icon_url', 'category', 'sort_order'],
        );

        $this->info('Upserted ' . count($items) . ' option records.');

        if (!$noFlush) {
            EncarOptionCatalog::flushCache();
            $this->info('Redis cache flushed.');
        }

        return 0;
    }

    /**
     * Fetch and parse the option catalog.
     *
     * Strategy:
     *  1. [0].optionlist → authoritative flat list (abbreviation=code, name=name_kr, type=category)
     *  2. [0].carOptions → build code→iconUrl map (leaf nodes + subOptions, skip group parents)
     *
     * @return array[]|null  null on error
     */
    private function fetchOptionItems(): ?array
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders(self::HEADERS)
                ->get(self::ENCAR_OPTION_LIST_URL, ['method' => 'optionlist']);

            if (!$response->successful()) {
                Log::warning('[ImportEncarOptions] HTTP ' . $response->status());
                return null;
            }

            $body = $response->json();

            if (!is_array($body) || empty($body[0]) || !is_array($body[0])) {
                Log::warning('[ImportEncarOptions] Unexpected response structure', [
                    'type'       => gettype($body),
                    'top_keys'   => is_array($body) && isset($body[0]) ? array_keys($body[0]) : [],
                ]);
                return null;
            }

            $data        = $body[0];
            $carOptions  = $data['carOptions'] ?? [];
            $optionList  = $data['optionlist']  ?? [];

            if (empty($optionList)) {
                Log::warning('[ImportEncarOptions] optionlist is empty');
                return null;
            }

            // ── Step 1: Build code → absolute icon URL from carOptions ──────────
            $imageMap = $this->buildImageMap($carOptions);

            // ── Step 2: Build result from optionlist (flat, authoritative) ───────
            $result = [];
            foreach ($optionList as $order => $item) {
                $code = trim((string) ($item['abbreviation'] ?? ''));
                $name = trim((string) ($item['name']         ?? ''));
                $type = trim((string) ($item['type']         ?? ''));

                if ($code === '' || $name === '') {
                    continue;
                }

                $result[] = [
                    'code'       => $code,
                    'name_kr'    => $name,
                    'icon_url'   => $imageMap[$code] ?? null,
                    'category'   => self::CATEGORY_MAP[$type] ?? null,
                    'sort_order' => $order,
                ];
            }

            return $result;

        } catch (\Throwable $e) {
            Log::error('[ImportEncarOptions] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Build a map of code → absolute icon URL from the carOptions tree.
     * Skips group-parent entries (isGroup=true) since their optionCd is a display alias.
     * For grouped options the subOptions carry the real codes.
     *
     * @param  array[] $carOptions
     * @return array<string, string>
     */
    private function buildImageMap(array $carOptions): array
    {
        $map = [];

        foreach ($carOptions as $opt) {
            $code      = (string) ($opt['optionCd']  ?? '');
            $imagePath = (string) ($opt['imagePath'] ?? '');
            $isGroup   = (bool)   ($opt['isGroup']   ?? $opt['group'] ?? false);
            $subOpts   = (array)  ($opt['subOptions'] ?? []);

            // Non-group leaf option — map code directly
            if ($code !== '' && $imagePath !== '' && !$isGroup) {
                $map[$code] = self::IMAGE_BASE_URL . $imagePath;
            }

            // Sub-options carry their own optionCd; use their own imagePath (falls back to parent)
            foreach ($subOpts as $sub) {
                $subCode = (string) ($sub['optionCd']  ?? '');
                $subPath = (string) ($sub['imagePath'] ?? $imagePath);
                if ($subCode !== '' && $subPath !== '') {
                    $map[$subCode] = self::IMAGE_BASE_URL . $subPath;
                }
            }
        }

        return $map;
    }
}
