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
        // Browser mode: authenticate via a linked browser_token — locally too, so the local
        // sign-in (routes/dev.php) acts as the user it linked, like the bot's link in production
        $browserToken = (string) ($request->input('browser_token') ?? '');
        if ($browserToken !== '') {
            $record = BrowserLinkToken::findValid($browserToken);
            if ($record && $record->isLinked()) {
                // Inject user_id so downstream controllers work unchanged
                $request->merge(['user_id' => $record->chat_id]);
                return $next($request);
            }
            if (!$this->bypassed()) {
                return response()->json(['ok' => false, 'error' => 'Browser token not linked or expired'], 401);
            }
        }

        if ($this->bypassed()) {
            return $next($request);
        }

        // Telegram WebApp mode: validate initData HMAC
        $initData = (string) ($request->input('init_data') ?? '');

        if (!$this->validate($initData)) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        // The user is the one Telegram signed, never a user_id the client sends alongside.
        parse_str($initData, $params);
        $user = json_decode((string) ($params['user'] ?? ''), true);
        if (!is_array($user) || empty($user['id'])) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }
        $request->merge(['user_id' => (int) $user['id']]);

        return $next($request);
    }

    /** Locally, and where MINIAPP_DEV_BYPASS is set outside production, an unsigned request passes. */
    private function bypassed(): bool
    {
        return app()->environment('local', 'development')
            || (config('app.miniapp_dev_bypass') && !app()->environment('production'));
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
