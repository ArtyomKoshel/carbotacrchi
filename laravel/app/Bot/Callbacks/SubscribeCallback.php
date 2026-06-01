<?php

namespace App\Bot\Callbacks;

use App\Bot\BotContext;
use App\Models\Subscription;
use App\Services\ProviderAggregator;
use App\Services\SearchQuery;
use App\Services\TelegramBot;

class SubscribeCallback
{
    public function __construct(
        private readonly TelegramBot        $bot,
        private readonly ProviderAggregator $aggregator,
    ) {}

    public function handle(BotContext $ctx, string $callbackId, string $encoded): void
    {
        $queryData = json_decode(base64_decode($encoded), true);

        if (!$queryData) {
            $this->bot->answerCallbackQuery($callbackId, 'Ошибка данных');
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
