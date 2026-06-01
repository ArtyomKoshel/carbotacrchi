<?php

namespace App\Bot\Commands;

use App\Bot\BotContext;
use App\Services\TelegramBot;
use Illuminate\Support\Facades\Artisan;

class DemoSeedCommand
{
    public function __construct(private readonly TelegramBot $bot) {}

    public function handle(BotContext $ctx): void
    {
        $this->bot->sendMessage($ctx->chatId, '⏳ Создаю демо-данные...');

        try {
            Artisan::call('demo:seed', ['--telegram-id' => $ctx->userId]);

            $name = htmlspecialchars($ctx->firstName);
            $this->bot->sendMessage($ctx->chatId,
                "✅ Готово, <b>{$name}</b>!\n\n"
                . "Созданы:\n"
                . "• 4 подписки (Toyota, Honda, BMW, Tesla)\n"
                . "• 6 избранных лотов\n\n"
                . "Теперь попробуй:\n"
                . "• /demo — симулировать уведомления\n"
                . "• /mysubs — посмотреть подписки\n"
                . "• Открой Mini App → Избранное"
            );
        } catch (\Throwable $e) {
            $this->bot->sendMessage($ctx->chatId, '❌ Ошибка: ' . htmlspecialchars($e->getMessage()));
        }
    }
}
