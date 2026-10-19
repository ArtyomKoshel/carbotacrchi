<?php

namespace App\Services\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Balances of paid services. Each check returns
 *   ['skipped' => reason]                          — not configured,
 *   ['ok' => false, 'error' => text]               — API failed (text holds no secrets),
 *   ['ok' => true, …values]                        — parsed balance.
 */
class BalanceChecker
{
    /** FloppyData traffic: ['gb' => float, 'expires_at' => ?CarbonImmutable]. */
    public function proxy(): array
    {
        $key = (string) config('auction.floppydata_api_key');
        if ($key === '') {
            return ['skipped' => 'FLOPPYDATA_API_KEY is not set'];
        }

        $base   = rtrim((string) config('auction.floppy_base_url'), '/');
        $errors = [];
        // v2 per FloppyData docs; v1 (used by the parser) as a fallback.
        foreach (['/v2/proxy/rotating/balance', '/v1/rotating/balance'] as $path) {
            try {
                $resp = Http::connectTimeout(5)->timeout(15)->acceptJson()
                    ->withHeader('X-Api-Key', $key)
                    ->get($base.$path);
            } catch (\Throwable $e) {
                $errors[] = "{$path}: ".class_basename($e);
                continue;
            }
            if (!$resp->successful()) {
                $errors[] = "{$path}: HTTP {$resp->status()}";
                continue;
            }
            $json   = $resp->json();
            $parsed = is_array($json) ? self::parseProxyBalance($json) : null;
            if ($parsed !== null) {
                return ['ok' => true, 'endpoint' => $path] + $parsed;
            }
            $errors[] = "{$path}: unrecognised response (keys: ".implode(',', array_keys((array) $json)).')';
        }

        return ['ok' => false, 'error' => implode('; ', $errors)];
    }

    /** DeepSeek account balance: ['amount' => float, 'currency' => string, 'available' => bool]. */
    public function ai(): array
    {
        // The AI key is only ever sent to DeepSeek when DeepSeek is the configured provider.
        $host = (string) parse_url((string) config('ai.api_url'), PHP_URL_HOST);
        if (!str_ends_with($host, 'deepseek.com')) {
            return ['skipped' => "AI provider is not DeepSeek ({$host})"];
        }

        $key = (string) (config('ai.api_key') ?: (config('ai.api_keys')[0] ?? ''));
        if ($key === '') {
            return ['skipped' => 'AI_API_KEY is not set'];
        }

        $base = rtrim((string) config('notifications.deepseek_base_url'), '/');
        try {
            $resp = Http::connectTimeout(5)->timeout(15)->acceptJson()
                ->withToken($key)
                ->get($base.'/user/balance');
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => class_basename($e)];
        }
        if (!$resp->successful()) {
            return ['ok' => false, 'error' => "HTTP {$resp->status()}"];
        }

        $infos = collect($resp->json('balance_infos') ?? []);
        $info  = $infos->firstWhere('currency', 'USD') ?? $infos->first();
        if (!is_array($info) || !isset($info['total_balance'])) {
            return ['ok' => false, 'error' => 'unrecognised response'];
        }

        return [
            'ok'        => true,
            'amount'    => (float) $info['total_balance'],
            'currency'  => (string) ($info['currency'] ?? 'USD'),
            'available' => (bool) ($resp->json('is_available') ?? true),
        ];
    }

    /**
     * v1 shape: {subscription: {gb, expiresOn}, nonExpiring: {gb}}. The v2 shape
     * is matched loosely: every numeric "gb"-like field is summed, and the expiry
     * of a pool counts only while that pool still holds traffic.
     *
     * @return array{gb: float, expires_at: ?CarbonImmutable}|null null when no GB field was found
     */
    public static function parseProxyBalance(array $data): ?array
    {
        // Known FloppyData shapes first. Both repeat the same traffic several
        // times (GB and bytes, per bucket and as a total), so the generic walk
        // below would count it 2-4x (v2 showed 55.95 GB for a real 14 GB).
        $date = static function ($v): ?CarbonImmutable {
            try {
                return is_string($v) && $v !== '' ? CarbonImmutable::parse($v) : null;
            } catch (\Throwable) {
                return null;
            }
        };
        $gbOf = static function ($traffic): ?float {
            if (!is_array($traffic)) {
                return null;
            }
            if (isset($traffic['availableGb']) && is_numeric($traffic['availableGb'])) {
                return (float) $traffic['availableGb'];
            }
            if (isset($traffic['availableBytes']) && is_numeric($traffic['availableBytes'])) {
                return (float) $traffic['availableBytes'] / 1024 ** 3;
            }
            return null;
        };

        // v2: {expiring: {expiresAt, traffic}, nonExpiring: {traffic}, total: {traffic}}
        if (isset($data['total']['traffic']) || isset($data['expiring']['traffic'])) {
            $total = $gbOf($data['total']['traffic'] ?? null)
                ?? (($gbOf($data['expiring']['traffic'] ?? null) ?? 0.0) + ($gbOf($data['nonExpiring']['traffic'] ?? null) ?? 0.0));
            $expiringGb = $gbOf($data['expiring']['traffic'] ?? null) ?? 0.0;
            return [
                'gb'         => round($total, 3),
                'expires_at' => $expiringGb > 0 ? $date($data['expiring']['expiresAt'] ?? null) : null,
            ];
        }

        // v1: {subscription: {expiresOn, bytes, gb}, nonExpiring: {bytes, gb}}
        if (isset($data['subscription']) && is_array($data['subscription'])) {
            $sub = (float) ($data['subscription']['gb'] ?? 0);
            return [
                'gb'         => round($sub + (float) ($data['nonExpiring']['gb'] ?? 0), 3),
                'expires_at' => $sub > 0 ? $date($data['subscription']['expiresOn'] ?? null) : null,
            ];
        }

        // Unknown shape: best-effort walk.
        $gb      = 0.0;
        $found   = false;
        $expires = null;

        $walk = function (array $node) use (&$walk, &$gb, &$found, &$expires): void {
            $nodeGb   = null;
            $nodeDate = null;
            foreach ($node as $k => $v) {
                if (is_array($v)) {
                    $walk($v);
                } elseif (is_string($k) && is_numeric($v) && preg_match('/^(gb|(remaining|left|available|balance|traffic)_?gb)$/i', $k)) {
                    $nodeGb = ($nodeGb ?? 0) + (float) $v;
                } elseif (is_string($k) && is_numeric($v) && preg_match('/^(remaining|left|available|balance)_?bytes$/i', $k)) {
                    $nodeGb = ($nodeGb ?? 0) + (float) $v / 1024 ** 3;
                } elseif (is_string($k) && is_string($v) && $v !== '' && preg_match('/expir/i', $k)) {
                    try {
                        $nodeDate = CarbonImmutable::parse($v);
                    } catch (\Throwable) {
                        // not a date
                    }
                }
            }
            if ($nodeGb !== null) {
                $found = true;
                $gb   += $nodeGb;
            }
            if ($nodeDate !== null && ($nodeGb === null || $nodeGb > 0)
                && ($expires === null || $nodeDate->lt($expires))) {
                $expires = $nodeDate;
            }
        };
        $walk($data);

        return $found ? ['gb' => round($gb, 3), 'expires_at' => $expires] : null;
    }
}
