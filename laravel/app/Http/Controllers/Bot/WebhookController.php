<?php

namespace App\Http\Controllers\Bot;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ProviderAggregator;
use App\Services\SearchQuery;
use App\Services\TelegramBot;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    private TelegramBot $bot;
    private string $miniAppUrl;

    public function handle(Request $request): Response
    {
        $update          = $request->all();
        $this->bot       = new TelegramBot(config('auction.bot_token'));
        $this->miniAppUrl = config('auction.miniapp_url', '');

        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return response('ok', 200);
        }

        $message = $update['message'] ?? null;
        if (!$message) {
            return response('ok', 200);
        }

        $chatId    = $message['chat']['id'];
        $userId    = $message['from']['id'];
        $text      = trim($message['text'] ?? '');
        $firstName = $message['from']['first_name'] ?? '';

        $this->upsertUser($message['from']);

        if ($text === '/start') {
            $this->handleStart($chatId, $firstName);
        } elseif ($text === '/help') {
            $this->handleHelp($chatId);
        } elseif ($text === '/mysubs') {
            $this->handleMySubs($chatId, $userId);
        } elseif ($text === '/demo') {
            $this->handleDemo($chatId, $userId);
        }

        $webAppData = $message['web_app_data']['data'] ?? null;
        if ($webAppData) {
            $this->handleWebAppData($chatId, $webAppData);
        }

        return response('ok', 200);
    }

    private function handleStart(int|string $chatId, string $firstName): void
    {
        $name = htmlspecialchars($firstName);

        $text = "👋 Привет, <b>{$name}</b>!\n\n"
            ."Я — бот для поиска авто на аукционах:\n\n"
            ."🇺🇸 <b>Copart</b> · <b>IAAI</b> · <b>Manheim</b>\n"
            ."🇰🇷 <b>Encar</b> · <b>KBChacha</b>\n\n"
            ."🔍 <b>Что я умею:</b>\n"
            ."• Поиск по 200+ маркам и моделям\n"
            ."• 13 фильтров: цена, пробег, кузов, КПП, привод…\n"
            ."• Подписки — уведомлю о новых лотах\n"
            ."• Избранное — сохраняй интересные варианты\n\n"
            ."Нажми <b>«Открыть поиск»</b> чтобы начать 👇";

        $keyboard = [];
        if ($this->miniAppUrl) {
            $keyboard[] = [['text' => '🔍 Открыть поиск', 'web_app' => ['url' => $this->miniAppUrl]]];
        }
        $keyboard[] = [
            ['text' => '🔔 Мои подписки', 'callback_data' => 'mysubs'],
            ['text' => '📨 Демо уведомления', 'callback_data' => 'demo_notify'],
        ];
        $keyboard[] = [['text' => 'ℹ️ Помощь', 'callback_data' => 'help']];

        $this->bot->sendMessageWithKeyboard($chatId, $text, $keyboard);
    }

    private function handleHelp(int|string $chatId): void
    {
        $text = "ℹ️ <b>Как пользоваться:</b>\n\n"
            ."1️⃣ Нажмите <b>«Открыть поиск»</b> — откроется приложение\n"
            ."2️⃣ Задайте фильтры: марка, модель, год, цена…\n"
            ."3️⃣ Нажмите <b>«Найти»</b> — увидите карточки лотов\n"
            ."4️⃣ Нажмите <b>«Подписаться»</b> — получайте уведомления о новых лотах\n\n"
            ."<b>Команды:</b>\n"
            ."/start — Главное меню\n"
            ."/mysubs — Мои подписки\n"
            ."/help — Эта справка";

        $keyboard = [];
        if ($this->miniAppUrl) {
            $keyboard[] = [['text' => '🔍 Открыть поиск', 'web_app' => ['url' => $this->miniAppUrl]]];
        }

        if ($keyboard) {
            $this->bot->sendMessageWithKeyboard($chatId, $text, $keyboard);
        } else {
            $this->bot->sendMessage($chatId, $text);
        }
    }

    private function handleMySubs(int|string $chatId, int|string $userId): void
    {
        $subs = Subscription::where('user_id', $userId)->active()->get();

        if ($subs->isEmpty()) {
            $text = "🔔 <b>Мои подписки</b>\n\nУ вас пока нет активных подписок.\n\nОткройте поиск, найдите интересные лоты и нажмите <b>«Подписаться»</b>.";
            $keyboard = [];
            if ($this->miniAppUrl) {
                $keyboard[] = [['text' => '🔍 Открыть поиск', 'web_app' => ['url' => $this->miniAppUrl]]];
            }
            if ($keyboard) {
                $this->bot->sendMessageWithKeyboard($chatId, $text, $keyboard);
            } else {
                $this->bot->sendMessage($chatId, $text);
            }
            return;
        }

        $text = "🔔 <b>Мои подписки</b> ({$subs->count()})\n\n";
        $keyboard = [];

        foreach ($subs as $i => $sub) {
            $num   = $i + 1;
            $label = htmlspecialchars($sub->label());
            $badge = $sub->new_lots_count > 0 ? " · <b>+{$sub->new_lots_count} новых</b>" : '';
            $text .= "{$num}. {$label}{$badge}\n";

            $keyboard[] = [
                ['text' => "❌ {$num}. {$sub->label()}", 'callback_data' => "unsub:{$sub->id}"],
            ];
        }

        if ($this->miniAppUrl) {
            $keyboard[] = [['text' => '📱 Открыть приложение', 'web_app' => ['url' => $this->miniAppUrl]]];
        }

        $this->bot->sendMessageWithKeyboard($chatId, $text, $keyboard);
    }

    private function handleDemo(int|string $chatId, int|string $userId): void
    {
        $subs = Subscription::where('user_id', $userId)->active()->get();

        if ($subs->isEmpty()) {
            $this->bot->sendMessage($chatId, "⚠️ Нет активных подписок.\n\nСначала откройте поиск, найдите лоты и нажмите <b>«Подписаться»</b>.");
            return;
        }

        $this->bot->sendMessage($chatId, "⏳ Симулирую проверку подписок...");

        $aggregator = app(ProviderAggregator::class);
        $sent = 0;

        foreach ($subs as $sub) {
            $query        = SearchQuery::fromArray($sub->query ?? []);
            $query->limit = 50;
            $result       = $aggregator->search($query);

            if (empty($result->lots)) {
                continue;
            }

            $fakeLots = array_slice($result->lots, 0, 3);
            $this->bot->notifyNewLots($chatId, $sub->label(), $fakeLots, $sub->id);

            $previews = array_map(fn ($l) => [
                'id' => $l->id, 'make' => $l->make, 'model' => $l->model,
                'year' => $l->year, 'price' => $l->price, 'imageUrl' => $l->imageUrl,
                'sourceName' => $l->sourceName, 'lotUrl' => $l->lotUrl ?? null,
                'damage' => $l->damage ?? null,
            ], $fakeLots);

            $sub->update([
                'last_checked_at'  => now(),
                'new_lots_count'   => $sub->new_lots_count + count($fakeLots),
                'new_lot_previews' => array_slice(array_merge($previews, $sub->new_lot_previews ?? []), 0, 5),
            ]);

            $sent++;
        }

        $this->bot->sendMessage($chatId, "✅ Готово! Отправлены уведомления по <b>{$sent}</b> подпискам.");
    }

    private function handleCallback(array $callback): void
    {
        $callbackId = $callback['id'];
        $chatId     = $callback['message']['chat']['id'] ?? null;
        $userId     = $callback['from']['id'] ?? null;
        $data       = $callback['data'] ?? '';

        if (!$chatId || !$userId) {
            $this->bot->answerCallbackQuery($callbackId);
            return;
        }

        if ($data === 'mysubs') {
            $this->bot->answerCallbackQuery($callbackId);
            $this->handleMySubs($chatId, $userId);
            return;
        }

        if ($data === 'help') {
            $this->bot->answerCallbackQuery($callbackId);
            $this->handleHelp($chatId);
            return;
        }

        if ($data === 'demo_notify') {
            $this->bot->answerCallbackQuery($callbackId, '⏳ Отправляю...');
            $this->handleDemo($chatId, $userId);
            return;
        }

        if (str_starts_with($data, 'unsub:')) {
            $subId = (int) substr($data, 6);
            $sub   = Subscription::where('id', $subId)->where('user_id', $userId)->active()->first();

            if ($sub) {
                $sub->update(['active' => false]);
                $this->bot->answerCallbackQuery($callbackId, '✅ Подписка удалена');
                $this->bot->sendMessage($chatId,
                    "🔕 Подписка <b>".htmlspecialchars($sub->label())."</b> удалена."
                );
            } else {
                $this->bot->answerCallbackQuery($callbackId, 'Подписка не найдена');
            }
            return;
        }

        $this->bot->answerCallbackQuery($callbackId);
    }

    private function handleWebAppData(int|string $chatId, string $webAppData): void
    {
        $data  = json_decode($webAppData, true);
        $lots  = $data['top_lots'] ?? [];
        $total = $data['total']    ?? 0;

        $this->bot->sendMessage($chatId, sprintf('🔍 Найдено <b>%d</b> лотов. Топ результаты:', $total));

        foreach (array_slice($lots, 0, 3) as $lot) {
            $lotUrl = $lot['lotUrl'] ?? '#';
            $buttons = $lotUrl !== '#'
                ? [[['text' => '🔗 Открыть лот', 'url' => $lotUrl]]]
                : null;
            $this->bot->sendLotCard($chatId, $lot, $buttons);
        }
    }

    private function upsertUser(array $from): void
    {
        try {
            User::updateOrCreate(
                ['id' => $from['id']],
                [
                    'username'   => $from['username'] ?? '',
                    'first_name' => $from['first_name'] ?? '',
                    'last_seen'  => now(),
                ]
            );
        } catch (\Throwable) {}
    }
}
