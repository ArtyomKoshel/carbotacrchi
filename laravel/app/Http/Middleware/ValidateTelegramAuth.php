<?php

namespace App\Http\Middleware;

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

        $initData = $request->input('init_data', '');

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
