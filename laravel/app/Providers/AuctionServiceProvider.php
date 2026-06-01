<?php

namespace App\Providers;

use App\AuctionProviders\EncarProvider;
use App\Bot\BotDispatcher;
use App\Services\ChatSearchService;
use App\Services\ProviderAggregator;
use App\Services\QueryExpansionService;
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
            );
        });

        $this->app->singleton(QueryExpansionService::class, function ($app) {
            return new QueryExpansionService($app->make(ProviderAggregator::class));
        });

        $this->app->singleton(ChatSearchService::class, fn () => new ChatSearchService());

        $this->app->singleton(BotDispatcher::class, function ($app) {
            return new BotDispatcher(
                $app->make(TelegramBot::class),
                $app->make(ProviderAggregator::class),
                $app->make(ChatSearchService::class),
            );
        });
    }

    public function boot(): void {}
}
