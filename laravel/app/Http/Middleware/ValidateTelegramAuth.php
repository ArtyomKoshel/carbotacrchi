<?php

namespace App\Http\Middleware;

use App\Models\BrowserLinkToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateTelegramAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local', 'development')) {
            return $next($request);
        }

        if (config('app.miniapp_dev_bypass') && !app()->environment('production')) {
            return $next($request);
        }

        // Browser mode: authenticate via a linked browser_token
        $browserToken = (string) ($request->input('browser_token') ?? '');
        if ($browserToken !== '') {
            $record = BrowserLinkToken::findValid($browserToken);
            if ($record && $record->isLinked()) {
                // Inject user_id so downstream controllers work unchanged
                $request->merge(['user_id' => $record->chat_id]);
                return $next($request);
            }
            return response()->json(['ok' => false, 'error' => 'Browser token not linked or expired'], 401);
        }

        // Telegram WebApp mode: validate initData HMAC
        $initData = (string) ($request->input('init_data') ?? '');

        if (!$this->validate($initData)) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }

    private function validate(string $initData): bool
    {
        if ($initData === '') {
            return false;
        }

        parse_str($initData, $params);
        $hash = $params['hash'] ?? '';
        unset($params['hash']);

        ksort($params);
        $dataCheckString = implode("\n", array_map(
            fn ($k, $v) => "$k=$v",
            array_keys($params),
            array_values($params)
        ));

        $secretKey    = hash_hmac('sha256', config('auction.bot_token'), 'WebAppData', true);
        $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        return hash_equals($expectedHash, $hash);
    }
}
