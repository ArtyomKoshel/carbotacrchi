<?php

namespace App\Bot\Callbacks;

use App\Bot\BotContext;
use App\Models\Subscription;
use App\Services\TelegramBot;

class UnsubscribeCallback
{
    public function __construct(private readonly TelegramBot $bot) {}

    public function handle(BotContext $ctx, string $callbackId, int $subId): void
    {
        $sub = Subscription::where('id', $subId)
            ->where('user_id', $ctx->userId)
            ->active()
            ->first();

        if ($sub) {
            $sub->update(['active' => false]);
            $this->bot->answerCallbackQuery($callbackId, '✅ Подписка удалена');
            $this->bot->sendMessage(
                $ctx->chatId,
                '🔕 Подписка <b>' . htmlspecialchars($sub->label()) . '</b> удалена.'
            );
        } else {
            $this->bot->answerCallbackQuery($callbackId, 'Подписка не найдена');
        }
    }
}
