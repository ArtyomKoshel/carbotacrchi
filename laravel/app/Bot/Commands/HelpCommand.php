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
            . "1️⃣ Нажмите <b>«Открыть поиск»</b> — откроется приложение\n"
            . "2️⃣ Задайте фильтры: марка, модель, год, цена…\n"
            . "3️⃣ Нажмите <b>«Найти»</b> — увидите карточки лотов\n"
            . "4️⃣ Нажмите <b>«Подписаться»</b> — получайте уведомления о новых лотах\n\n"
            . "<b>Команды:</b>\n"
            . "/start — Главное меню\n"
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
