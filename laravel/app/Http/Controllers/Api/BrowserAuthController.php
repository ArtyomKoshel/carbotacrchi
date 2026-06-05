<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrowserLinkToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrowserAuthController extends Controller
{
    /**
     * Issue a new browser link token and return the Telegram deep link.
     *
     * POST /api/auth/browser-init
     * Body: {} (no params required)
     * Response: { token, bot_url }
     */
    public function init(): JsonResponse
    {
        $record  = BrowserLinkToken::generate();
        $botName = config('auction.bot_username', '');

        $botUrl = $botName
            ? "https://t.me/{$botName}?start=link_{$record->token}"
            : null;

        return response()->json([
            'ok'   => true,
            'data' => [
                'token'   => $record->token,
                'bot_url' => $botUrl,
            ],
        ]);
    }

    /**
     * Poll the link status for a browser token.
     *
     * GET /api/auth/browser-status?token=TOKEN
     * Response: { linked, chat_id, first_name, username }
     */
    public function status(Request $request): JsonResponse
    {
        $token  = trim((string) $request->query('token', ''));
        $record = $token ? BrowserLinkToken::findValid($token) : null;

        if (!$record) {
            return response()->json(['ok' => false, 'error' => 'Token not found or expired'], 404);
        }

        return response()->json([
            'ok'   => true,
            'data' => [
                'linked'     => $record->isLinked(),
                'chat_id'    => $record->chat_id,
                'first_name' => $record->first_name,
                'username'   => $record->username,
            ],
        ]);
    }
}
