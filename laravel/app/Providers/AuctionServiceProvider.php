<?php

namespace App\Providers;

use App\AuctionProviders\CopartProvider;
use App\AuctionProviders\EncarProvider;
use App\AuctionProviders\IAIProvider;
use App\AuctionProviders\KBChachaProvider;
use App\AuctionProviders\ManheimProvider;
use App\Repositories\LotRepositoryInterface;
use App\Repositories\MockLotRepository;
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
                new CopartProvider(),
                new IAIProvider(),
                new ManheimProvider(),
                new EncarProvider(),
                new KBChachaProvider(),
            );
        });

        $this->app->singleton(LotRepositoryInterface::class, function ($app) {
            $driver = config('auction.lot_repository', 'mock');

            return match ($driver) {
                default => new MockLotRepository($app->make(ProviderAggregator::class)),
            };
        });
    }

    public function boot(): void {}
}
