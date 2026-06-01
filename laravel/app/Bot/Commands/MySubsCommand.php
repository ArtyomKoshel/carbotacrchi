<?php

namespace App\Bot\Commands;

use App\Bot\BotContext;
use App\Models\Subscription;
use App\Services\TelegramBot;

class MySubsCommand
{
    public function __construct(
        private readonly TelegramBot $bot,
        private readonly string      $miniAppUrl,
    ) {}

    public function handle(BotContext $ctx): void
    {
        $subs = Subscription::where('user_id', $ctx->userId)->active()->get();

        if ($subs->isEmpty()) {
            $text     = "🔔 <b>Мои подписки</b>\n\nУ вас пока нет активных подписок.\n\nОткройте поиск, найдите интересные лоты и нажмите <b>«Подписаться»</b>.";
            $keyboard = [];
            if ($this->miniAppUrl) {
                $keyboard[] = [['text' => '🔍 Открыть поиск', 'web_app' => ['url' => $this->miniAppUrl]]];
            }
            $keyboard
                ? $this->bot->sendMessageWithKeyboard($ctx->chatId, $text, $keyboard)
                : $this->bot->sendMessage($ctx->chatId, $text);
            return;
        }

        $text     = "🔔 <b>Мои подписки</b> ({$subs->count()})\n\n";
        $keyboard = [];

        foreach ($subs as $i => $sub) {
            $num   = $i + 1;
            $label = htmlspecialchars($sub->label());
            $badge = $sub->new_lots_count > 0 ? " · <b>+{$sub->new_lots_count} новых</b>" : '';
            $text .= "{$num}. {$label}{$badge}\n";
            $keyboard[] = [['text' => "❌ {$num}. {$sub->label()}", 'callback_data' => "unsub:{$sub->id}"]];
        }

        if ($this->miniAppUrl) {
            $keyboard[] = [['text' => '📱 Открыть приложение', 'web_app' => ['url' => $this->miniAppUrl]]];
        }

        $this->bot->sendMessageWithKeyboard($ctx->chatId, $text, $keyboard);
    }
}
