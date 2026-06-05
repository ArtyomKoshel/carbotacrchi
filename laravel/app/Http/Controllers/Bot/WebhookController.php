<?php

namespace App\Http\Controllers\Bot;

use App\Bot\BotDispatcher;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private readonly BotDispatcher $dispatcher) {}

    public function handle(Request $request): Response
    {
        $update   = $request->all();
        $updateId = $update['update_id'] ?? null;

        // Log every incoming update so we know the request is arriving
        Log::info('[webhook] received', [
            'update_id' => $updateId,
            'type'      => isset($update['message']) ? 'message'
                         : (isset($update['callback_query']) ? 'callback' : 'other'),
        ]);

        // Dedup — soft-fail if Redis is down
        if ($updateId) {
            try {
                $key = 'tg_upd_' . $updateId;
                if (Cache::has($key)) {
                    Log::info('[webhook] duplicate, skipping', ['update_id' => $updateId]);
                    return response('ok', 200);
                }
                Cache::put($key, 1, 300);
            } catch (\Throwable $e) {
                Log::warning('[webhook] dedup cache failed: ' . $e->getMessage());
            }
        }

        try {
            $this->dispatcher->dispatch($update);
        } catch (\Throwable $e) {
            Log::error('[webhook] dispatch failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response('ok', 200);
    }
}
