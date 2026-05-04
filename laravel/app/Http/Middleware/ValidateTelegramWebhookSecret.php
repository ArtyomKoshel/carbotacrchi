<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateTelegramWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('auction.webhook_secret', '');

        if ($secret === '') {
            return $next($request);
        }

        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($provided === '' || !hash_equals($secret, $provided)) {
            return response('Unauthorized', 401);
        }

        return $next($request);
    }
}
