<?php

namespace App\Bot\Commands;

use App\Bot\BotContext;
use App\Bot\ConversationState;
use App\Services\ChatSearchService;
use App\Services\ProviderAggregator;
use App\Services\TelegramBot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TextSearchCommand
{
    public function __construct(
        private readonly TelegramBot        $bot,
        private readonly ProviderAggregator $aggregator,
        private readonly ChatSearchService  $chatSearch,
        private readonly string             $miniAppUrl,
    ) {}

    public function handle(BotContext $ctx, string $text): void
    {
        try {
            $this->doSearch($ctx, $text);
        } catch (\Throwable $e) {
            Log::error('[bot] search error', [
                'user_id'  => $ctx->userId,
                'username' => $ctx->username,
                'text'     => $text,
                'error'    => $e->getMessage(),
            ]);
            $this->bot->sendMessage($ctx->chatId, '❌ Произошла ошибка при поиске. Попробуйте позже.');
        }
    }

    private function doSearch(BotContext $ctx, string $text): void
    {
        $prevState = ConversationState::get($ctx->userId);

        Log::info('[bot] chat query', [
            'user_id'   => $ctx->userId,
            'username'  => $ctx->username,
            'prompt'    => $text,
            'has_state' => $prevState !== null,
        ]);

        $parsed = $this->chatSearch->parseAndSearch($text, $prevState);

        if ($parsed === null) {
            Log::warning('[bot] parse returned null', ['user_id' => $ctx->userId, 'prompt' => $text]);
            $this->bot->sendMessage($ctx->chatId,
                "🤔 Не удалось распознать поисковый запрос.\n\n"
                . "<b>Попробуйте например:</b>\n"
                . "• «BMW 5 серия до 20000$»\n"
                . "• «Hyundai Sonata 2020 автомат»\n"
                . "• «кроссовер до 15 млн вон без ДТП»\n\n"
                . "Или нажмите <b>«Открыть поиск»</b> для фильтров 👇"
            );
            return;
        }

        $query         = $parsed['query'];
        $tolerantQuery = $parsed['tolerantQuery'];
        $description   = $parsed['description'];
        $toleranceNote = $parsed['toleranceNote'];
        $isAi          = $parsed['isAi']    ?? false;
        $isRefined     = $parsed['refined'] ?? false;

        Log::info('[bot] parsed filters', [
            'user_id'  => $ctx->userId,
            'username' => $ctx->username,
            'mode'     => $isAi ? 'ai' : 'fallback',
            'refined'  => $isRefined,
            'filters'  => array_filter($query->toSearchArray(), fn ($v) => $v !== null && $v !== '' && $v !== []),
        ]);

        $modeTag = $isAi ? '🤖' : '📋';
        if ($isRefined && $prevState) {
            $statusText = "🔄 Уточняю: <b>{$description}</b>";
        } else {
            $statusText = "{$modeTag} Ищу: <b>{$description}</b>";
        }
        if ($toleranceNote) {
            $statusText .= "\n📊 {$toleranceNote}";
        }
        $this->bot->sendMessage($ctx->chatId, $statusText);

        $t0     = microtime(true);
        $result = $this->aggregator->search($tolerantQuery);
        $ms     = (int) ((microtime(true) - $t0) * 1000);

        Log::info('[bot] search result', [
            'user_id' => $ctx->userId,
            'prompt'  => $text,
            'total'   => $result->total,
            'ms'      => $ms,
        ]);

        if (empty($result->lots)) {
            $this->bot->sendMessage($ctx->chatId, '😕 Ничего не найдено. Попробуйте изменить запрос или уточните параметры.');
            return;
        }

        // Save state for follow-up refinements
        ConversationState::save($ctx->userId, $query->toSearchArray(), $result->total, $description);

        $this->bot->sendMessage($ctx->chatId, "✅ Найдено <b>{$result->total}</b> лотов. Топ-5:");

        foreach (array_slice($result->lots, 0, 5) as $lot) {
            $lotData = $lot->toArray();
            $lotUrl  = $lotData['lotUrl'] ?? '#';
            $buttons = $lotUrl !== '#' ? [[['text' => '🔗 Открыть лот', 'url' => $lotUrl]]] : null;
            $this->bot->sendLotCard($ctx->chatId, $lotData, $buttons);
        }

        $queryArray    = array_filter($query->toSearchArray(), fn ($v) => $v !== null && $v !== '' && $v !== []);
        $footerButtons = [];

        if ($this->miniAppUrl) {
            $separator       = str_contains($this->miniAppUrl, '?') ? '&' : '?';
            $payload         = json_encode($queryArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $deepLink        = $this->miniAppUrl . $separator . 'search=' . rawurlencode($payload ?: '{}');
            $footerButtons[] = [['text' => '📱 Все результаты (' . $result->total . ')', 'web_app' => ['url' => $deepLink]]];
            $this->bot->setChatMenuButton($ctx->chatId, '🔍 Все результаты (' . $result->total . ')', $deepLink);
        }
        // Store query in cache — Telegram callback_data limit is 64 bytes
        $queryKey = 'bq_' . Str::random(8);
        Cache::put($queryKey, $queryArray, 86400);
        $footerButtons[] = [['text' => '🔔 Подписаться', 'callback_data' => 'sub_chat:' . $queryKey]];
        $footerButtons[] = [['text' => '🔄 Новый поиск', 'callback_data' => 'new_search']];

        $hint = $result->total > 5
            ? "Показаны 5 из <b>{$result->total}</b>. Уточните: «подешевле», «без ДТП», «с люком»… или нажмите 🔄 для нового поиска."
            : "Уточните: «подешевле», «без ДТП», «новее»… или нажмите 🔄 для нового поиска.";

        $this->bot->sendMessageWithKeyboard($ctx->chatId, $hint, $footerButtons);
    }
}
