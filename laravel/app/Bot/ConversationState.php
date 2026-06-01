<?php

namespace App\Bot;

use Illuminate\Support\Facades\Cache;

class ConversationState
{
    private const TTL_SECONDS = 3600;

    public static function get(int|string $userId): ?array
    {
        return Cache::get("bot_conv_{$userId}");
    }

    public static function save(int|string $userId, array $query, int $total, string $description): void
    {
        Cache::put("bot_conv_{$userId}", [
            'query'       => $query,
            'total'       => $total,
            'description' => $description,
        ], self::TTL_SECONDS);
    }

    public static function clear(int|string $userId): void
    {
        Cache::forget("bot_conv_{$userId}");
    }
}
