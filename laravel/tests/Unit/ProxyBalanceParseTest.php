<?php

namespace Tests\Unit;

use App\Services\Notifications\BalanceChecker;
use PHPUnit\Framework\TestCase;

/**
 * FloppyData balance parsing. Payloads are the real prod responses of 2026-10-19.
 *
 * Run: vendor/bin/phpunit (from laravel/)
 */
class ProxyBalanceParseTest extends TestCase
{
    public function test_v2_counts_traffic_once(): void
    {
        $r = BalanceChecker::parseProxyBalance([
            'expiring'    => ['expiresAt' => '2026-11-18T00:00:00Z', 'traffic' => ['availableBytes' => 15024716005, 'availableGb' => 13.992857192642987]],
            'nonExpiring' => ['traffic' => ['availableBytes' => 204554, 'availableGb' => 0.0001905057579278946]],
            'total'       => ['traffic' => ['availableBytes' => 15024920559, 'availableGb' => 13.993047698400915]],
        ]);

        $this->assertSame(13.993, $r['gb']);
        $this->assertSame('2026-11-18', $r['expires_at']->format('Y-m-d'));
    }

    public function test_v2_expired_subscription_has_no_expiry(): void
    {
        $r = BalanceChecker::parseProxyBalance([
            'expiring'    => ['expiresAt' => '2026-07-01T00:00:00Z', 'traffic' => ['availableBytes' => 0, 'availableGb' => 0]],
            'nonExpiring' => ['traffic' => ['availableBytes' => 204554, 'availableGb' => 0.00019]],
            'total'       => ['traffic' => ['availableBytes' => 204554, 'availableGb' => 0.00019]],
        ]);

        $this->assertSame(0.0, $r['gb']);
        $this->assertNull($r['expires_at']);
    }

    public function test_v1_adds_subscription_and_non_expiring(): void
    {
        $r = BalanceChecker::parseProxyBalance([
            'subscription' => ['expiresOn' => '2026-11-18T00:00:00Z', 'bytes' => 15024716005, 'gb' => 13.992857192642987],
            'nonExpiring'  => ['bytes' => 204554, 'gb' => 0.0001905057579278946],
        ]);

        $this->assertSame(13.993, $r['gb']);
        $this->assertSame('2026-11-18', $r['expires_at']->format('Y-m-d'));
    }
}
