<?php

namespace App\Filament\Widgets;

use App\Models\NotificationSetting;
use App\Services\Notifications\BalanceChecker;
use App\Services\Notifications\NotificationState;
use Filament\Widgets\Widget;

/**
 * «Обзор»: proxy (FloppyData) and DeepSeek balances. Normally the result of
 * notifications:watch, which checks every 15 minutes; when that is missing
 * or older than an hour (watcher not running), the balance is asked live.
 */
class BalancesWidget extends Widget
{
    protected string $view = 'filament.widgets.balances';

    protected static ?int $sort = 3;

    private const STALE_MINUTES = 60;

    protected function getViewData(): array
    {
        $status = [];
        try {
            $status = (array) app(NotificationState::class)->get('last_status', []);
        } catch (\Throwable) {
            // state store unavailable: fall through to a live check
        }

        $proxy = $this->fresh($status['proxy'] ?? null) ?? $this->live('proxy');
        $ai    = $this->fresh($status['ai'] ?? null) ?? $this->live('ai');
        $th    = rescue(fn () => NotificationSetting::thresholds(), [], false);

        return [
            'proxy'   => $proxy,
            'ai'      => $ai,
            'minGb'   => $th['proxy_min_gb'] ?? null,
            'minUsd'  => $th['ai_min_usd'] ?? null,
        ];
    }

    private function fresh(?array $row): ?array
    {
        if (!$row || empty($row['at'])) {
            return null;
        }

        return now()->diffInMinutes($row['at'], true) <= self::STALE_MINUTES ? $row : null;
    }

    /** Same shape notifications:watch stores. */
    private function live(string $which): array
    {
        $res = $which === 'proxy' ? app(BalanceChecker::class)->proxy() : app(BalanceChecker::class)->ai();
        $res['at'] = now()->toIso8601String();
        $res['live'] = true;
        if (isset($res['expires_at']) && $res['expires_at'] instanceof \DateTimeInterface) {
            $res['expires_at'] = $res['expires_at']->format(DATE_ATOM);
        }

        return $res;
    }
}
