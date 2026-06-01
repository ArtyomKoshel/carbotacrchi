<?php

namespace App\Http\Controllers\Bot;

use App\Bot\BotDispatcher;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class WebhookController extends Controller
{
    public function __construct(private readonly BotDispatcher $dispatcher) {}

    public function handle(Request $request): Response
    {
        $update   = $request->all();
        $updateId = $update['update_id'] ?? null;

        if ($updateId) {
            $key = 'tg_upd_' . $updateId;
            if (Cache::has($key)) {
                return response('ok', 200);
            }
            Cache::put($key, 1, 300);
        }

        $this->dispatcher->dispatch($update);

        return response('ok', 200);
    }
}
