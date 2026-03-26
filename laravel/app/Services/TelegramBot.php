<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBot
{
    private string $token;
    private string $apiBase = 'https://api.telegram.org/bot';

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function sendMessage(int|string $chatId, string $text, array $extra = []): array
    {
        return $this->request('sendMessage', array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $extra));
    }

    public function sendMessageWithKeyboard(int|string $chatId, string $text, array $inlineKeyboard): array
    {
        return $this->sendMessage($chatId, $text, [
            'reply_markup' => json_encode([
                'inline_keyboard' => $inlineKeyboard,
            ]),
        ]);
    }

    public function sendPhoto(int|string $chatId, string $photoUrl, string $caption, array $extra = []): array
    {
        return $this->request('sendPhoto', array_merge([
            'chat_id'    => $chatId,
            'photo'      => $photoUrl,
            'caption'    => $caption,
            'parse_mode' => 'HTML',
        ], $extra));
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, ?array $inlineKeyboard = null): array
    {
        $params = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];

        if ($inlineKeyboard !== null) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $inlineKeyboard,
            ]);
        }

        return $this->request('editMessageText', $params);
    }

    public function answerCallbackQuery(string $callbackId, ?string $text = null, bool $showAlert = false): array
    {
        $params = ['callback_query_id' => $callbackId];
        if ($text !== null) {
            $params['text']       = $text;
            $params['show_alert'] = $showAlert;
        }

        return $this->request('answerCallbackQuery', $params);
    }

    public function sendLotCard(int|string $chatId, array $lot, ?array $inlineKeyboard = null): array
    {
        $price = number_format((int) $lot['price'], 0, '.', ',');
        $km    = number_format((int) $lot['mileage'], 0, '.', ',');
        $date  = !empty($lot['auctionDate'])
                 ? date('d M', strtotime($lot['auctionDate']))
                 : '—';
        $lotId = str_replace(($lot['source'] ?? '').'_', '', $lot['id'] ?? '');

        $text = sprintf(
            "🚗 <b>%s %s %d</b> · %s\n💰 <b>$%s</b> · Lot #%s\n📍 %s · 🗓 %s\n%s🛣 %s km",
            htmlspecialchars($lot['make']       ?? ''),
            htmlspecialchars($lot['model']      ?? ''),
            (int) ($lot['year'] ?? 0),
            htmlspecialchars($lot['sourceName'] ?? ''),
            $price,
            htmlspecialchars($lotId),
            htmlspecialchars($lot['location']   ?? ''),
            $date,
            !empty($lot['damage']) ? '💥 '.htmlspecialchars($lot['damage']).' · ' : '',
            $km,
        );

        $lotUrl = $lot['lotUrl'] ?? '#';
        if ($lotUrl !== '#') {
            $text .= sprintf("\n🔗 <a href=\"%s\">Открыть лот</a>", htmlspecialchars($lotUrl));
        }

        $imageUrl = $lot['imageUrl'] ?? null;

        if ($imageUrl && $inlineKeyboard) {
            return $this->sendPhoto($chatId, $imageUrl, $text, [
                'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
            ]);
        }

        if ($imageUrl) {
            return $this->sendPhoto($chatId, $imageUrl, $text);
        }

        if ($inlineKeyboard) {
            return $this->sendMessageWithKeyboard($chatId, $text, $inlineKeyboard);
        }

        return $this->sendMessage($chatId, $text);
    }

    private function ruLots(int $n): string
    {
        $mod10  = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) return 'новый лот';
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) return 'новых лота';
        return 'новых лотов';
    }

    public function notifyNewLots(int|string $chatId, string $label, array $lots, ?int $subscriptionId = null): void
    {
        $count     = count($lots);
        $miniAppUrl = config('auction.miniapp_url');

        $this->sendMessage($chatId,
            "🔔 <b>Обновление подписки: {$label}</b>\n"
            ."Найдено <b>{$count}</b> ".($this->ruLots($count)).'!'
        );

        foreach (array_slice($lots, 0, 5) as $lot) {
            $lotData = (array) $lot;
            $lotUrl  = $lotData['lotUrl'] ?? '#';

            $buttons = [];
            if ($lotUrl !== '#') {
                $buttons[] = [['text' => '🔗 Открыть лот', 'url' => $lotUrl]];
            }

            $this->sendLotCard($chatId, $lotData, $buttons ?: null);
        }

        $footerButtons = [];
        if ($miniAppUrl) {
            $footerButtons[] = [['text' => '📱 Все результаты', 'web_app' => ['url' => $miniAppUrl]]];
        }
        if ($subscriptionId) {
            $footerButtons[] = [['text' => '🔕 Отписаться', 'callback_data' => "unsub:{$subscriptionId}"]];
        }

        if ($count > 5) {
            $this->sendMessageWithKeyboard(
                $chatId,
                '...и ещё <b>'.($count - 5).'</b>. Откройте приложение, чтобы увидеть все.',
                $footerButtons
            );
        } elseif ($footerButtons) {
            $this->sendMessageWithKeyboard($chatId, '—', $footerButtons);
        }
    }

    private function request(string $method, array $params): array
    {
        try {
            $response = Http::timeout(10)
                ->post($this->apiBase.$this->token.'/'.$method, $params);

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::error('[TelegramBot] '.$method.' failed: '.$e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
