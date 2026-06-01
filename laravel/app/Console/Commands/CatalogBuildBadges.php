<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Populate catalog_badges from Encar iNav full taxonomy JSON
 * (produced by analysis/scrape_encar_inav_3levels.py or the full iNav scraper).
 *
 * JSON format expected: encar_inav_tree.json
 *   {
 *     "taxonomy": [
 *       {
 *         "make_kr": "현대", "make_en": "Hyundai",
 *         "model_group_kr": "쏘나타",
 *         "model_kr": "쏘나타(DN8)19-23",
 *         "badge_group_kr": "가솔린 1600cc",   ← fuel + engine_volume
 *         "badge_kr": "1.6 터보",               ← badge as stored in lots.badge
 *         "badge_detail_kr": "프레스티지",       ← trim (null for many)
 *         "count": 123
 *       }, ...
 *     ]
 *   }
 *
 * badge_group_kr encodes fuel and displacement:
 *   "가솔린 1600cc" → fuel=gasoline, engine_volume=1.6
 *   "디젤 2200cc"   → fuel=diesel,   engine_volume=2.2
 *   "전기"          → fuel=ev,        engine_volume=null
 *   "LPG 2000cc"    → fuel=lpg,       engine_volume=2.0
 *
 * drive_type is read from badge_kr via the shared vocabulary lookup (no regex).
 * seat_count is read from the "N인승" prefix in badge_kr.
 *
 * Usage:
 *   php artisan catalog:build-badges ../analysis/encar_inav_tree.json
 *   php artisan catalog:build-badges ../analysis/encar_inav_tree.json --apply
 *   php artisan catalog:build-badges ../analysis/encar_inav_tree.json --apply --fresh
 */
class CatalogBuildBadges extends Command
{
    protected $signature = 'catalog:build-badges
        {file : Path to encar_inav_tree.json}
        {--apply : Persist changes (default: dry-run)}
        {--fresh : Truncate catalog_badges before import}';

    protected $description = 'Populate catalog_badges from iNav taxonomy JSON (no regex, no static parsing)';

    // badge_group_kr prefix → canonical fuel value
    // These are stable Encar enum strings — vocabulary mapping, not text parsing.
    private const FUEL_PREFIX_MAP = [
        '가솔린+전기' => 'hybrid',
        '디젤+전기'   => 'hybrid',
        'LPG+전기'    => 'hybrid',
        '하이브리드'  => 'hybrid',
        'HEV'         => 'hybrid',
        '플러그인하이브리드' => 'plugin_hybrid',
        'PHEV'        => 'plugin_hybrid',
        '수소전기'    => 'hydrogen',
        '수소'        => 'hydrogen',
        '가솔린'      => 'gasoline',
        '휘발유'      => 'gasoline',
        '디젤'        => 'diesel',
        '경유'        => 'diesel',
        '전기'        => 'ev',
        'LPG'         => 'lpg',
        'CNG'         => 'cng',
    ];

    // Tokens found in badge_kr that encode drive type.
    // Pure vocabulary lookup — no string manipulation.
    private const DRIVE_TOKEN_MAP = [
        'AWD'    => 'awd',
        '4WD'    => 'awd',
        '4wd'    => 'awd',
        '4Matic' => 'awd',
        '4MATIC' => 'awd',
        'xDrive' => 'awd',
        'quattro'=> 'awd',
        '콰트로'  => 'awd',
        'FWD'    => 'fwd',
        '2WD'    => 'fwd',
        '2wd'    => 'fwd',
        'RWD'    => 'rwd',
        'sDrive' => 'rwd',
    ];

    public function handle(): int
    {
        $file  = (string) $this->argument('file');
        $apply = (bool) $this->option('apply');
        $fresh = (bool) $this->option('fresh');

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($file), true);
        $rows = $data['taxonomy'] ?? null;

        if (! is_array($rows)) {
            $this->error('Invalid JSON or missing "taxonomy" key.');
            return self::FAILURE;
        }

        $this->info('catalog:build-badges  mode=' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line(sprintf('Input: %d taxonomy rows', count($rows)));
        $this->line('');

        if ($fresh && $apply) {
            $this->line('  <fg=red>TRUNCATE</>  catalog_badges');
            DB::table('catalog_badges')->truncate();
        }

        // Pre-load existing keys: "make_kr::model_group_kr::badge_kr" → true
        $existing = DB::table('catalog_badges')
            ->get(['make_kr', 'model_group_kr', 'badge_kr'])
            ->mapWithKeys(fn ($r) => ["{$r->make_kr}::{$r->model_group_kr}::{$r->badge_kr}" => true])
            ->toArray();

        $new = 0;
        $skip = 0;
        $batch = [];

        foreach ($rows as $row) {
            $makeKr      = trim($row['make_kr']       ?? '');
            $mgKr        = trim($row['model_group_kr'] ?? '');
            $badgeKr     = trim($row['badge_kr']       ?? '');
            $badgeGroupKr = trim($row['badge_group_kr'] ?? '');
            $badgeDetailKr = trim($row['badge_detail_kr'] ?? '') ?: null;

            if ($makeKr === '' || $mgKr === '' || $badgeKr === '') {
                continue;
            }

            $cacheKey = "{$makeKr}::{$mgKr}::{$badgeKr}";
            if (isset($existing[$cacheKey])) {
                $skip++;
                continue;
            }
            $existing[$cacheKey] = true;

            $fuel        = $this->parseFuel($badgeGroupKr);
            $engineVol   = $this->parseEngineVolume($badgeGroupKr);
            $driveType   = $this->parseDriveType($badgeKr);
            $isTurbo     = $this->parseIsTurbo($badgeGroupKr, $badgeKr);
            $seatCount   = $this->parseSeatCount($badgeKr);
            $badgeDetailKr = $this->parseTrimKr($badgeKr, $badgeDetailKr, $seatCount);

            $new++;
            if ($new <= 20) {
                $this->line(sprintf(
                    '  + %-10s %-20s %-35s  fuel=%-12s eng=%-4s drive=%-5s seats=%s trim=%s',
                    $makeKr, $mgKr, $badgeKr,
                    $fuel ?? '—', $engineVol ?? '—', $driveType ?? '—',
                    $seatCount ?? '—', $badgeDetailKr ?? '—'
                ));
            } elseif ($new === 21) {
                $this->line('  ... (more rows omitted from preview)');
            }

            $batch[] = [
                'make_kr'        => $makeKr,
                'model_group_kr' => $mgKr,
                'badge_kr'       => $badgeKr,
                'fuel'           => $fuel,
                'engine_volume'  => $engineVol,
                'drive_type'     => $driveType,
                'is_turbo'       => $isTurbo,
                'seat_count'     => $seatCount,
                'trim_kr'        => $badgeDetailKr,
                'lot_count'      => (int) ($row['count'] ?? 0),
                'created_at'     => now(),
                'updated_at'     => now(),
            ];

            if (count($batch) >= 200 && $apply) {
                DB::table('catalog_badges')->insertOrIgnore($batch);
                $batch = [];
            }
        }

        if ($apply && ! empty($batch)) {
            DB::table('catalog_badges')->insertOrIgnore($batch);
        }

        $this->line('');
        $this->info("Done. New: {$new}  Skipped (exists): {$skip}");
        if (! $apply) {
            $this->comment('Dry-run — pass --apply to persist.');
        }

        return self::SUCCESS;
    }

    /**
     * Parse fuel type from badge_group_kr.
     * badge_group_kr is a structured Encar enum — lookup only, no parsing.
     * Examples: "가솔린 1600cc", "디젤 2200cc", "전기", "LPG 2000cc"
     */
    private function parseFuel(string $badgeGroupKr): ?string
    {
        foreach (self::FUEL_PREFIX_MAP as $prefix => $canonical) {
            if (str_starts_with($badgeGroupKr, $prefix)) {
                return $canonical;
            }
        }
        return null;
    }

    /**
     * Parse engine volume (in litres) from badge_group_kr.
     * Encar stores displacement as "NNNNcc" inside badge_group_kr.
     * Examples: "가솔린 1600cc" → 1.6, "디젤 2200cc" → 2.2, "전기" → null
     *
     * No regex: split by space, find token ending in "cc", divide by 1000.
     */
    private function parseEngineVolume(string $badgeGroupKr): ?float
    {
        foreach (explode(' ', $badgeGroupKr) as $token) {
            $lower = mb_strtolower($token);
            if (str_ends_with($lower, 'cc')) {
                $cc = (int) substr($lower, 0, -2);
                if ($cc >= 100 && $cc <= 20000) {
                    return round($cc / 1000, 1);
                }
            }
        }
        return null;
    }

    /**
     * Parse drive type from badge_kr tokens.
     * Drive type tokens are explicit Encar keywords — vocabulary lookup.
     * Examples: "가솔린 2.5T 4WD" → awd, "HEV 1.6 2WD" → fwd
     */
    private function parseDriveType(string $badgeKr): ?string
    {
        foreach (explode(' ', $badgeKr) as $token) {
            if (isset(self::DRIVE_TOKEN_MAP[$token])) {
                return self::DRIVE_TOKEN_MAP[$token];
            }
        }
        return null;
    }

    /**
     * Determine if the badge is for a turbocharged engine.
     * Encar encodes turbo as "T" suffix on the displacement in badge_group_kr
     * or as part of badge_kr like "1.6T", "2.0T".
     *
     * Check for " T " or "T\d" patterns in badge_kr tokens.
     */
    private function parseIsTurbo(string $badgeGroupKr, string $badgeKr): bool
    {
        // Some badge_group_kr values explicitly say "터보": "가솔린 터보 1600cc"
        if (str_contains($badgeGroupKr, '터보')) {
            return true;
        }
        // badge_kr tokens like "1.6T", "2.0T", "T-GDI"
        foreach (explode(' ', $badgeKr) as $token) {
            // ends with T (case-sensitive Encar convention)
            if (str_ends_with($token, 'T') && strlen($token) > 1) {
                $prefix = substr($token, 0, -1);
                if (is_numeric($prefix) || str_contains($prefix, '.')) {
                    return true;
                }
            }
            // Explicit turbo tokens
            if (in_array($token, ['T-GDI', 'TGDI', 'TCe'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract seat count from badge_kr.
     * Encar encodes seat count as "N인승" token: "9인승 시그니처" → 9.
     *
     * The token can be anywhere after a fuel prefix:
     *   "9인승 노블레스"          → 9
     *   "가솔린 9인승 시그니처"   → 9
     *
     * No regex — pure string token scan.
     */
    private function parseSeatCount(string $badgeKr): ?int
    {
        foreach (explode(' ', $badgeKr) as $token) {
            if (str_ends_with($token, '인승')) {
                $digits = substr($token, 0, -strlen('인승'));
                if (ctype_digit($digits) && $digits !== '') {
                    $n = (int) $digits;
                    if ($n >= 1 && $n <= 45) {
                        return $n;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Extract trim (комплектация) from badge_kr + badge_detail_kr.
     *
     * Priority:
     *  1. badge_detail_kr from iNav — authoritative when present
     *     ("9인승 하이리무진" + badge_detail_kr="프레스티지" → "프레스티지")
     *
     *  2. Remainder after stripping fuel prefix + seat-count token from badge_kr
     *     ("9인승 노블레스"        → "노블레스")
     *     ("가솔린 9인승 시그니처" → "시그니처")
     *     ("가솔린 2.5T 2WD"      → null — no seat count means badge IS the spec)
     *
     * This mirrors how Encar's filter UI displays trim: it strips the
     * seat/fuel prefix and shows only the grade name.
     */
    private function parseTrimKr(string $badgeKr, ?string $badgeDetailKr, ?int $seatCount): ?string
    {
        // Source 1: explicit badge_detail_kr from iNav (highest priority)
        if ($badgeDetailKr !== null && $badgeDetailKr !== '') {
            return $badgeDetailKr;
        }

        // Source 2: extract from badge_kr only when seat count is present
        // (no seat count = badge encodes engine/drive, not a grade name)
        if ($seatCount === null) {
            return null;
        }

        $s = $badgeKr;

        // Strip known fuel prefixes from the start (pure vocabulary lookup)
        foreach (array_keys(self::FUEL_PREFIX_MAP) as $prefix) {
            $prefixWithSpace = $prefix . ' ';
            if (str_starts_with($s, $prefixWithSpace)) {
                $s = substr($s, strlen($prefixWithSpace));
                break;
            }
        }

        // Strip "N인승" token from the start
        $seatToken = $seatCount . '인승 ';
        if (str_starts_with($s, $seatToken)) {
            $remaining = trim(substr($s, strlen($seatToken)));
            return $remaining !== '' ? $remaining : null;
        }

        return null;
    }
}
