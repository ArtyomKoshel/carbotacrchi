<?php

namespace App\Services\Calculator;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Official rates of the National Bank of Belarus, BYN per 1 USD and per 1 EUR: customs duty
 * is set in euro and paid in roubles, the calculator counts in dollars. Asked at most once an
 * hour; when the bank does not answer, the last rates it gave are used and marked stale.
 */
final class NbrbRates
{
    private const FRESH = 'calculator:nbrb';
    private const LAST = 'calculator:nbrb:last';

    /** @return array{usd: ?float, eur: ?float, date: ?string, stale: bool} */
    public function current(): array
    {
        $fresh = Cache::get(self::FRESH);
        if (is_array($fresh)) {
            return $fresh + ['stale' => false];
        }

        $fetched = $this->fetch();
        if ($fetched !== null) {
            Cache::put(self::FRESH, $fetched, 3600);
            Cache::forever(self::LAST, $fetched);

            return $fetched + ['stale' => false];
        }

        $last = Cache::get(self::LAST);

        return is_array($last) ? $last + ['stale' => true] : ['usd' => null, 'eur' => null, 'date' => null, 'stale' => true];
    }

    /** @return array{usd: float, eur: float, date: string}|null */
    private function fetch(): ?array
    {
        $base = rtrim((string) config('calculator.nbrb_url'), '/');
        try {
            $rate = function (string $code) use ($base): array {
                $r = Http::timeout(6)->acceptJson()->get("{$base}/{$code}", ['parammode' => 2]);
                $value = (float) $r->json('Cur_OfficialRate');
                $scale = max(1, (int) $r->json('Cur_Scale'));
                if (! $r->successful() || $value <= 0) {
                    throw new \RuntimeException("{$code}: HTTP {$r->status()}");
                }

                return [$value / $scale, substr((string) $r->json('Date'), 0, 10)];
            };
            [$usd, $date] = $rate('USD');
            [$eur] = $rate('EUR');

            return ['usd' => $usd, 'eur' => $eur, 'date' => $date];
        } catch (\Throwable $e) {
            Log::warning('[Calculator] NBRB: ' . $e->getMessage());

            return null;
        }
    }
}
