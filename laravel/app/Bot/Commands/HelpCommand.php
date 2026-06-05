<?php

namespace App\Bot\Commands;

use App\Bot\BotContext;
use App\Services\TelegramBot;

class HelpCommand
{
    public function __construct(
        private readonly TelegramBot $bot,
        private readonly string      $miniAppUrl,
    ) {}

    public function handle(BotContext $ctx): void
    {
        $text = "ℹ️ <b>Как пользоваться:</b>\n\n"
            . "1️⃣ Напишите запрос: <i>«Kia EV3 без ДТП»</i>\n"
            . "2️⃣ Уточняйте в ответах: <i>«подешевле», «с кожаным салоном»</i>\n"
            . "3️⃣ Нажмите <b>🔄 Новый поиск</b> чтобы начать заново\n"
            . "4️⃣ Или откройте приложение для ручных фильтров\n\n"
            . "<b>Команды:</b>\n"
            . "/start — Главное меню\n"
            . "/new — 🔄 Новый поиск (сбросить историю)\n"
            . "/mysubs — Мои подписки\n"
            . "/help — Эта справка";

        $keyboard = [];
        if ($this->miniAppUrl) {
            $keyboard[] = [['text' => '🔍 Открыть поиск', 'web_app' => ['url' => $this->miniAppUrl]]];
        }

        if ($keyboard) {
            $this->bot->sendMessageWithKeyboard($ctx->chatId, $text, $keyboard);
        } else {
            $this->bot->sendMessage($ctx->chatId, $text);
        }
    }
}
