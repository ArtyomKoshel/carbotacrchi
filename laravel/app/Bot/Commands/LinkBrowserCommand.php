<?php

namespace App\Bot\Commands;

use App\Bot\BotContext;
use App\Models\BrowserLinkToken;
use App\Services\TelegramBot;

class LinkBrowserCommand
{
    public function __construct(
        private readonly TelegramBot $bot,
        private readonly string      $miniAppUrl,
    ) {}

    public function handle(BotContext $ctx, string $token): void
    {
        $record = BrowserLinkToken::findValid($token);

        if (!$record) {
            $this->bot->sendMessage(
                $ctx->chatId,
                "⚠️ Ссылка недействительна или истекла.\n\nОткройте браузерную версию заново и нажмите «Привязать Telegram»."
            );
            return;
        }

        if ($record->isLinked() && $record->chat_id === $ctx->userId) {
            $this->bot->sendMessage($ctx->chatId, '✅ Браузер уже привязан к этому чату.');
            return;
        }

        $record->linkTo($ctx->userId, $ctx->firstName, $ctx->username);

        $name    = htmlspecialchars($ctx->firstName);
        $webLink = $this->miniAppUrl ? "\n\n🔗 <a href=\"{$this->miniAppUrl}\">Открыть в браузере</a>" : '';

        $this->bot->sendMessage(
            $ctx->chatId,
            "✅ <b>Браузер привязан!</b>\n\n"
            . "Привет, <b>{$name}</b>! Теперь уведомления о подписках из браузерной версии будут приходить сюда.{$webLink}"
        );
    }
}
