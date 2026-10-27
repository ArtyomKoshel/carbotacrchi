<?php

namespace App\Services\Calculator;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * USDT/KRW on Bithumb, the rate the sales team quotes. Asked at most once per
 * calculator.rate_cache_seconds for everyone; when Bithumb does not answer, the last
 * rate it gave is used and marked stale.
 */
final class BithumbRate
{
    private const FRESH = 'calculator:bithumb';
    private const LAST = 'calculator:bithumb:last';

    /** @return array{rate: ?float, at: ?string, stale: bool} */
    public function current(): array
    {
        $fresh = Cache::get(self::FRESH);
        if (is_array($fresh)) {
            return $fresh + ['stale' => false];
        }

        $fetched = $this->fetch();
        if ($fetched !== null) {
            Cache::put(self::FRESH, $fetched, max(30, (int) config('calculator.rate_cache_seconds', 300)));
            Cache::forever(self::LAST, $fetched);

            return $fetched + ['stale' => false];
        }

        $last = Cache::get(self::LAST);

        return is_array($last) ? $last + ['stale' => true] : ['rate' => null, 'at' => null, 'stale' => true];
    }

    /** @return array{rate: float, at: string}|null */
    private function fetch(): ?array
    {
        try {
            $response = Http::timeout(5)->acceptJson()->get((string) config('calculator.bithumb_url'));
            $rate = (float) $response->json('data.closing_price');
            if ($response->successful() && $rate > 0) {
                return ['rate' => $rate, 'at' => now()->toIso8601String()];
            }
            Log::warning('[Calculator] Bithumb: HTTP ' . $response->status());
        } catch (\Throwable $e) {
            Log::warning('[Calculator] Bithumb: ' . $e->getMessage());
        }

        return null;
    }
}
