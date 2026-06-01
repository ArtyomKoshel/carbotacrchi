<?php

namespace App\Bot\Commands;

use App\Bot\BotContext;
use App\Models\Subscription;
use App\Services\ProviderAggregator;
use App\Services\SearchQuery;
use App\Services\TelegramBot;

class DemoCommand
{
    public function __construct(
        private readonly TelegramBot        $bot,
        private readonly ProviderAggregator $aggregator,
    ) {}

    public function handle(BotContext $ctx): void
    {
        $subs = Subscription::where('user_id', $ctx->userId)->active()->get();

        if ($subs->isEmpty()) {
            $this->bot->sendMessage($ctx->chatId, "⚠️ Нет активных подписок.\n\nСначала откройте поиск, найдите лоты и нажмите <b>«Подписаться»</b>.");
            return;
        }

        $this->bot->sendMessage($ctx->chatId, '⏳ Симулирую проверку подписок...');
        $sent = 0;

        foreach ($subs as $sub) {
            $query        = SearchQuery::fromArray($sub->query ?? []);
            $query->limit = 50;
            $result       = $this->aggregator->search($query);

            if (empty($result->lots)) {
                continue;
            }

            $fakeLots = array_slice($result->lots, 0, 3);
            $this->bot->notifyNewLots($ctx->chatId, $sub->label(), $fakeLots, $sub->id, $sub->query ?? []);

            $previews = array_map(fn ($l) => [
                'id' => $l->id, 'make' => $l->make, 'model' => $l->model,
                'year' => $l->year, 'price' => $l->price, 'imageUrl' => $l->imageUrl,
                'sourceName' => $l->sourceName, 'lotUrl' => $l->lotUrl ?? null,
            ], $fakeLots);

            $sub->update([
                'last_checked_at'  => now(),
                'new_lots_count'   => $sub->new_lots_count + count($fakeLots),
                'new_lot_previews' => array_slice(array_merge($previews, $sub->new_lot_previews ?? []), 0, 5),
            ]);

            $sent++;
        }

        $this->bot->sendMessage($ctx->chatId, "✅ Готово! Отправлены уведомления по <b>{$sent}</b> подпискам.");
    }
}
