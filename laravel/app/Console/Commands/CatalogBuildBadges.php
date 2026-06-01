<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Build catalog_badges from all unique Badge strings in lots.
 *
 * Badge strings have a standardised Encar format:
 *   "가솔린 2.5T 4WD"   → fuel=gasoline, engine=2.5, drive=4wd, turbo=true
 *   "디젤 2.2 4WD"       → fuel=diesel,   engine=2.2, drive=4wd, turbo=false
 *   "전기"               → fuel=ev,        engine=null, drive=null
 *   "가솔린+전기 1.8 2WD" → fuel=hybrid,   engine=1.8, drive=2wd
 *   "LPG 2.0 2WD"        → fuel=lpg,       engine=2.0, drive=2wd
 *
 * This parsing runs ONCE here — the parser/normalizer just do lookup.
 *
 * Usage:
 *   php artisan catalog:build-badges            # dry-run
 *   php artisan catalog:build-badges --apply    # persist
 */
class CatalogBuildBadges extends Command
{
    protected $signature = 'catalog:build-badges
        {--apply : Persist changes (default: dry-run)}
        {--source=encar : Source to read badges from}';

    protected $description = 'Build catalog_badges from unique Badge strings in lots';

    private const FUEL_MAP = [
        '가솔린+전기' => 'hybrid',
        '디젤+전기'   => 'hybrid',
        '가솔린'      => 'gasoline',
        '휘발유'      => 'gasoline',
        '디젤'        => 'diesel',
        '경유'        => 'diesel',
        '전기'        => 'ev',
        'LPG'         => 'lpg',
        '수소'        => 'hydrogen',
        '하이브리드'  => 'hybrid',
    ];

    private const DRIVE_MAP = [
        '4WD'    => '4wd',
        '4wd'    => '4wd',
        'AWD'    => 'awd',
        'awd'    => 'awd',
        'xDrive' => 'awd',
        '4MATIC' => 'awd',
        'quattro'=> 'awd',
        '콰트로'  => 'awd',
        '2WD'    => '2wd',
        '2wd'    => '2wd',
        'FWD'    => 'fwd',
        'RWD'    => 'rwd',
        'sDrive' => 'rwd',
    ];

    public function handle(): int
    {
        $apply  = (bool) $this->option('apply');
        $source = (string) $this->option('source');

        $this->info('catalog:build-badges  mode=' . ($apply ? 'APPLY' : 'DRY-RUN'));

        // Collect all unique badge strings from lots
        $badges = DB::table('lots')
            ->where('source', $source)
            ->whereNotNull('badge')
            ->where('badge', '!=', '')
            ->selectRaw('badge AS badge_kr, COUNT(*) AS lot_count')
            ->groupBy('badge')
            ->orderByDesc('lot_count')
            ->get();

        $this->line("Unique badges found: {$badges->count()}");

        $existing = DB::table('catalog_badges')
            ->pluck('badge_kr')
            ->flip()
            ->toArray();

        $new = 0;
        $upd = 0;

        foreach ($badges as $row) {
            $badgeKr  = $row->badge_kr;
            $parsed   = $this->parseBadge($badgeKr);

            if (! isset($existing[$badgeKr])) {
                $new++;
                $this->line(sprintf(
                    '  + %-40s  fuel=%-10s  eng=%-5s  drive=%-5s  turbo=%s',
                    $badgeKr,
                    $parsed['fuel'] ?? '—',
                    $parsed['engine_volume'] ?? '—',
                    $parsed['drive_type'] ?? '—',
                    $parsed['is_turbo'] ? 'Y' : 'N'
                ));
                if ($apply) {
                    DB::table('catalog_badges')->insert([
                        'badge_kr'      => $badgeKr,
                        'fuel'          => $parsed['fuel'],
                        'engine_volume' => $parsed['engine_volume'],
                        'drive_type'    => $parsed['drive_type'],
                        'is_turbo'      => $parsed['is_turbo'],
                        'lot_count'     => $row->lot_count,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }
            } else {
                // Update lot_count
                $upd++;
                if ($apply) {
                    DB::table('catalog_badges')
                        ->where('badge_kr', $badgeKr)
                        ->update(['lot_count' => $row->lot_count, 'updated_at' => now()]);
                }
            }
        }

        $this->line('');
        $this->info("Done. New: {$new}  Updated counts: {$upd}");
        if (! $apply) {
            $this->comment('Dry-run — pass --apply to persist.');
        }

        return self::SUCCESS;
    }

    private function parseBadge(string $badge): array
    {
        $result = [
            'fuel'          => null,
            'engine_volume' => null,
            'drive_type'    => null,
            'is_turbo'      => false,
        ];

        $s = trim($badge);

        // ── Fuel ─────────────────────────────────────────────────────────────
        foreach (self::FUEL_MAP as $token => $canonical) {
            if (str_starts_with($s, $token)) {
                $result['fuel'] = $canonical;
                $s = trim(substr($s, strlen($token)));
                break;
            }
        }

        // ── Drive type ────────────────────────────────────────────────────────
        foreach (self::DRIVE_MAP as $token => $canonical) {
            if (str_contains($s, $token)) {
                $result['drive_type'] = $canonical;
                $s = str_replace($token, '', $s);
                $s = trim($s);
                break;
            }
        }

        // ── Engine volume: "2.5T" "2.5" "2500cc" "1600cc" ─────────────────
        // Pattern: optional digits/dot followed by optional T/L, or ccXXXX
        if (preg_match('/(\d+(?:\.\d+)?)\s*(T|L)?(?:\s|$)/i', $s, $m)) {
            $vol = (float) $m[1];
            $suffix = strtoupper($m[2] ?? '');
            // Convert cc to liters if >= 100
            if ($vol >= 100) {
                $vol = round($vol / 1000, 1);
            }
            if ($vol > 0 && $vol <= 20) {
                $result['engine_volume'] = $vol;
                $result['is_turbo']      = ($suffix === 'T');
            }
        }

        // cc notation: "2500cc"
        if ($result['engine_volume'] === null && preg_match('/(\d+)\s*cc/i', $s, $m)) {
            $result['engine_volume'] = round((int) $m[1] / 1000, 1);
        }

        return $result;
    }
}
