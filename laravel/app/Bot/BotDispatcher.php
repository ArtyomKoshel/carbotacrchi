<?php

namespace App\Bot;

use App\Bot\Callbacks\SubscribeCallback;
use App\Bot\Callbacks\UnsubscribeCallback;
use App\Bot\Commands\HelpCommand;
use App\Bot\Commands\LinkBrowserCommand;
use App\Bot\Commands\MySubsCommand;
use App\Bot\Commands\NewSearchCommand;
use App\Bot\Commands\StartCommand;
use App\Bot\Commands\TextSearchCommand;
use App\Models\User;
use App\Services\ChatSearchService;
use App\Services\ProviderAggregator;
use App\Services\TelegramBot;
use Illuminate\Support\Facades\Log;

class BotDispatcher
{
    private readonly string $miniAppUrl;

    public function __construct(
        private readonly TelegramBot        $bot,
        private readonly ProviderAggregator $aggregator,
        private readonly ChatSearchService  $chatSearch,
    ) {
        $this->miniAppUrl = (string) config('auction.miniapp_url', '');
    }

    public function dispatch(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->dispatchCallback($update['callback_query']);
            return;
        }

        $message = $update['message'] ?? null;
        if (!$message) {
            return;
        }

        $ctx  = $this->contextFromMessage($message);
        $text = trim($message['text'] ?? '');

        $this->upsertUser($message['from']);

        Log::info('[bot] message', [
            'user_id'  => $ctx->userId,
            'username' => $ctx->username,
            'text'     => mb_substr($text, 0, 200),
        ]);

        // /start link_TOKEN — browser linking takes priority over regular /start
        $linkToken = $this->extractLinkToken($text);

        match (true) {
            $linkToken !== null                            => (new LinkBrowserCommand($this->bot, $this->miniAppUrl))->handle($ctx, $linkToken),
            $text === '/start'                            => (new StartCommand($this->bot, $this->miniAppUrl))->handle($ctx),
            $text === '/help'                             => (new HelpCommand($this->bot, $this->miniAppUrl))->handle($ctx),
            $text === '/mysubs'                           => (new MySubsCommand($this->bot, $this->miniAppUrl))->handle($ctx),
            in_array($text, ['/new', '/reset', '/clear']) => (new NewSearchCommand($this->bot, $this->miniAppUrl))->handle($ctx),
            $text !== '' && !str_starts_with($text, '/') => (new TextSearchCommand($this->bot, $this->aggregator, $this->chatSearch, $this->miniAppUrl))->handle($ctx, $text),
            default                                       => null,
        };

        $webAppData = $message['web_app_data']['data'] ?? null;
        if ($webAppData) {
            $this->dispatchWebAppData($ctx, $webAppData);
        }
    }

    private function dispatchCallback(array $callback): void
    {
        $callbackId = $callback['id'];
        $chatId     = $callback['message']['chat']['id'] ?? null;
        $userId     = $callback['from']['id'] ?? null;

        if (!$chatId || !$userId) {
            $this->bot->answerCallbackQuery($callbackId);
            return;
        }

        $ctx  = new BotContext($chatId, $userId, $callback['from']['first_name'] ?? '', $callback['from']['username'] ?? '');
        $data = $callback['data'] ?? '';

        match (true) {
            $data === 'mysubs'     => $this->tap($callbackId, fn () => (new MySubsCommand($this->bot, $this->miniAppUrl))->handle($ctx)),
            $data === 'help'       => $this->tap($callbackId, fn () => (new HelpCommand($this->bot, $this->miniAppUrl))->handle($ctx)),
            $data === 'new_search' => $this->tap($callbackId, fn () => (new NewSearchCommand($this->bot, $this->miniAppUrl))->handle($ctx)),

            str_starts_with($data, 'sub_chat:') => (new SubscribeCallback($this->bot, $this->aggregator))
                ->handle($ctx, $callbackId, substr($data, 9)),

            str_starts_with($data, 'unsub:') => (new UnsubscribeCallback($this->bot))
                ->handle($ctx, $callbackId, (int) substr($data, 6)),

            default => $this->bot->answerCallbackQuery($callbackId),
        };
    }

    private function dispatchWebAppData(BotContext $ctx, string $webAppData): void
    {
        $data  = json_decode($webAppData, true);
        $lots  = $data['top_lots'] ?? [];
        $total = $data['total']    ?? 0;

        $this->bot->sendMessage($ctx->chatId, sprintf('🔍 Найдено <b>%d</b> лотов. Топ результаты:', $total));

        foreach (array_slice($lots, 0, 3) as $lot) {
            $lotUrl  = $lot['lotUrl'] ?? '#';
            $buttons = $lotUrl !== '#' ? [[['text' => '🔗 Открыть лот', 'url' => $lotUrl]]] : null;
            $this->bot->sendLotCard($ctx->chatId, $lot, $buttons);
        }
    }

    private function tap(string $callbackId, callable $fn, ?string $text = null): void
    {
        $this->bot->answerCallbackQuery($callbackId, $text);
        $fn();
    }

    private function contextFromMessage(array $message): BotContext
    {
        return new BotContext(
            chatId:    $message['chat']['id'],
            userId:    $message['from']['id'],
            firstName: $message['from']['first_name'] ?? '',
            username:  $message['from']['username']   ?? '',
        );
    }

    private function upsertUser(array $from): void
    {
        try {
            User::updateOrCreate(
                ['id' => $from['id']],
                ['username' => $from['username'] ?? '', 'first_name' => $from['first_name'] ?? '', 'last_seen' => now()]
            );
        } catch (\Throwable) {}
    }

    /**
     * Extract the browser link token from "/start link_TOKEN" messages.
     * Returns null for any other message.
     */
    private function extractLinkToken(string $text): ?string
    {
        if (preg_match('/^\/start\s+link_([A-Za-z0-9]+)$/', $text, $m)) {
            return $m[1];
        }
        return null;
    }
}
