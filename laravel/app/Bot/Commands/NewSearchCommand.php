<?php

namespace App\Bot\Commands;

use App\Bot\BotContext;
use App\Bot\ConversationState;
use App\Services\TelegramBot;

class NewSearchCommand
{
    public function __construct(
        private readonly TelegramBot $bot,
        private readonly string      $miniAppUrl,
    ) {}

    public function handle(BotContext $ctx): void
    {
        ConversationState::clear($ctx->userId);

        $keyboard = [];
        if ($this->miniAppUrl) {
            $keyboard[] = [['text' => '🔍 Открыть поиск', 'web_app' => ['url' => $this->miniAppUrl]]];
        }

        $this->bot->sendMessageWithKeyboard(
            $ctx->chatId,
            "🔄 История поиска очищена.\n\nНапишите новый запрос или откройте приложение.",
            $keyboard
        );
    }
}
