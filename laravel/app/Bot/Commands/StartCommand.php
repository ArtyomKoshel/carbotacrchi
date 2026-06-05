<?php

namespace App\Bot\Commands;

use App\Bot\BotContext;
use App\Services\TelegramBot;

class StartCommand
{
    public function __construct(
        private readonly TelegramBot $bot,
        private readonly string      $miniAppUrl,
    ) {}

    public function handle(BotContext $ctx): void
    {
        $name = htmlspecialchars($ctx->firstName);

        $text = "👋 Привет, <b>{$name}</b>!\n\n"
            . "Я — бот для поиска авто на аукционах:\n\n"
            . "🇰🇷 <b>Encar</b>\n\n"
            . "🔍 <b>Что я умею:</b>\n"
            . "• Поиск по 200+ маркам и моделям\n"
            . "• 13 фильтров: цена, пробег, кузов, КПП, привод…\n"
            . "• Подписки — уведомлю о новых лотах\n"
            . "• Избранное — сохраняй интересные варианты\n\n"
            . "Нажми <b>«Открыть поиск»</b> чтобы начать 👇";

        $keyboard = [];
        if ($this->miniAppUrl) {
            $keyboard[] = [['text' => '🔍 Открыть поиск', 'web_app' => ['url' => $this->miniAppUrl]]];
            $this->bot->setChatMenuButton($ctx->chatId, '🔍 Открыть поиск', $this->miniAppUrl);
        }
        $keyboard[] = [
            ['text' => '🔔 Мои подписки', 'callback_data' => 'mysubs'],
            ['text' => 'ℹ️ Помощь', 'callback_data' => 'help'],
        ];

        $this->bot->sendMessageWithKeyboard($ctx->chatId, $text, $keyboard);
    }
}
