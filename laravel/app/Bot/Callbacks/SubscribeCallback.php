<?php

namespace App\Bot\Callbacks;

use App\Bot\BotContext;
use App\Models\Subscription;
use App\Services\ProviderAggregator;
use App\Services\SearchQuery;
use App\Services\TelegramBot;
use Illuminate\Support\Facades\Cache;

class SubscribeCallback
{
    public function __construct(
        private readonly TelegramBot        $bot,
        private readonly ProviderAggregator $aggregator,
    ) {}

    public function handle(BotContext $ctx, string $callbackId, string $encoded): void
    {
        // New format: short cache key (bq_XXXXXXXX)
        // Legacy format: base64-encoded JSON (for backward compat)
        $queryData = Cache::get($encoded);
        if (!$queryData) {
            $queryData = json_decode(base64_decode($encoded), true);
        }

        if (!$queryData) {
            $this->bot->answerCallbackQuery($callbackId, 'Ошибка: запрос устарел, выполните поиск заново');
            return;
        }

        $normalizedQuery = SearchQuery::fromArray($queryData)->toSearchArray();

        $query        = SearchQuery::fromArray($normalizedQuery);
        $query->limit = 100;
        $result       = $this->aggregator->search($query);

        $sub = Subscription::create([
            'user_id'         => $ctx->userId,
            'query'           => $normalizedQuery,
            'active'          => true,
            'last_checked_at' => now(),
        ]);

        $sub->markLotsAsSeen(array_map(fn ($l) => $l->id, $result->lots));

        $this->bot->answerCallbackQuery($callbackId, '✅ Подписка создана!');
        $this->bot->sendMessage(
            $ctx->chatId,
            "🔔 Подписка создана: <b>" . htmlspecialchars($sub->label()) . "</b>\n\n"
            . "Вы получите уведомление, когда появятся новые лоты."
        );
    }
}
