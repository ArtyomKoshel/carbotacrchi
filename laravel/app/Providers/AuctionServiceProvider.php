<?php

namespace App\Providers;

use App\AuctionProviders\EncarProvider;
use App\AuctionProviders\KBChachaProvider;
use App\Services\ProviderAggregator;
use App\Services\TelegramBot;
use Illuminate\Support\ServiceProvider;

class AuctionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TelegramBot::class, function () {
            return new TelegramBot((string) env('TELEGRAM_BOT_TOKEN', ''));
        });

        $this->app->singleton(ProviderAggregator::class, function () {
            return (new ProviderAggregator())->register(
                new EncarProvider(),
                new KBChachaProvider(),
            );
        });
    }

    public function boot(): void {}
}
