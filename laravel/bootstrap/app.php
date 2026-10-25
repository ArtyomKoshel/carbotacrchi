<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // sign-in without Telegram or a password, for local work and the UI tests only
            if (app()->environment('local')) {
                require __DIR__.'/../routes/dev.php';
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
        $middleware->preventRequestForgery(except: [
            'bot/webhook',
        ]);
        $middleware->alias([
            'telegram.auth'    => \App\Http\Middleware\ValidateTelegramAuth::class,
            'telegram.webhook' => \App\Http\Middleware\ValidateTelegramWebhookSecret::class,
            'internal.token'   => \App\Http\Middleware\ValidateInternalToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
