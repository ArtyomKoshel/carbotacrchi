<?php

namespace App\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Ensure logs always go to laravel.log regardless of LOG_CHANNEL env var.
        // On Railway LOG_CHANNEL=stderr sends logs to stdout only; this adds file output.
        if (env('LOG_CHANNEL', 'stack') !== 'stack') {
            try {
                $logPath = storage_path('logs/laravel.log');
                $handler = new RotatingFileHandler($logPath, 7, Level::Debug);
                /** @var \Illuminate\Log\Logger $laravelLogger */
                $laravelLogger = app(\Illuminate\Log\LogManager::class)->driver();
                /** @var \Monolog\Logger $monolog */
                $monolog = $laravelLogger->getLogger();
                $monolog->pushHandler($handler);
            } catch (\Throwable) {
                // non-fatal: if we can't attach the file handler, continue anyway
            }
        }
    }
}
