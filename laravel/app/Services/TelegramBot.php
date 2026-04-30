<?php

namespace App\Services;

use App\Models\BotFilterSetting;
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
        $text = $this->buildLotCardText($lot);

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

    private function buildLotCardText(array $lot): string
    {
        $cardFields = BotFilterSetting::getCardFields();
        if (!$cardFields) {
            $cardFields = ['fuel', 'transmission', 'engine_volume', 'insurance_count', 'has_accident', 'owners_count'];
        }

        $price = number_format((int) ($lot['price'] ?? 0), 0, '.', ',');
        $km = number_format((int) ($lot['mileage'] ?? 0), 0, '.', ',');

        $lines = [
            sprintf(
                '🚗 <b>%s %s %d</b> · %s',
                htmlspecialchars((string) ($lot['make'] ?? '')),
                htmlspecialchars((string) ($lot['model'] ?? '')),
                (int) ($lot['year'] ?? 0),
                htmlspecialchars((string) ($lot['sourceName'] ?? '')),
            ),
            sprintf('💰 <b>₩%s</b> · 🛣 %s km', $price, $km),
        ];

        $specs = [];
        if (in_array('fuel', $cardFields, true) && !empty($lot['fuel'])) {
            $specs[] = '⛽ ' . htmlspecialchars((string) $lot['fuel']);
        }
        if (in_array('transmission', $cardFields, true) && !empty($lot['transmission'])) {
            $specs[] = '⚙️ ' . htmlspecialchars((string) $lot['transmission']);
        }
        if (in_array('engine_volume', $cardFields, true) && !empty($lot['engineVolume'])) {
            $specs[] = htmlspecialchars((string) $lot['engineVolume']) . 'L';
        }
        if (in_array('drive_type', $cardFields, true) && !empty($lot['driveType'])) {
            $specs[] = htmlspecialchars((string) $lot['driveType']);
        }
        if ($specs) {
            $lines[] = implode(' · ', $specs);
        }

        $condition = [];
        if (in_array('insurance_count', $cardFields, true)) {
            $insuranceCount = $lot['insuranceCount'] ?? null;
            if ($insuranceCount !== null && $insuranceCount !== '') {
                $ic = (int) $insuranceCount;
                $condition[] = "📋 {$ic} " . $this->ruInsurance($ic);
            }
        }
        if (in_array('has_accident', $cardFields, true)) {
            $hasAccident = $lot['hasAccident'] ?? null;
            if ($hasAccident === true) {
                $condition[] = '⚠️ Были ДТП';
            } elseif ($hasAccident === false) {
                $condition[] = '✅ Без ДТП';
            }
        }
        if (in_array('owners_count', $cardFields, true)) {
            $ownersCount = $lot['ownersCount'] ?? null;
            if ($ownersCount !== null && $ownersCount !== '') {
                $oc = (int) $ownersCount;
                $condition[] = "👤 {$oc} " . $this->ruOwners($oc);
            }
        }
        if (in_array('flood_history', $cardFields, true)) {
            $floodHistory = $lot['floodHistory'] ?? null;
            if ($floodHistory === true) {
                $condition[] = '🌊 Затопление';
            }
        }
        if ($condition) {
            $lines[] = implode(' · ', $condition);
        }

        if (!empty($lot['location'])) {
            $lines[] = '📍 ' . htmlspecialchars((string) $lot['location']);
        }

        $lotUrl = $lot['lotUrl'] ?? '#';
        if ($lotUrl !== '#') {
            $lines[] = sprintf('🔗 <a href="%s">Открыть лот</a>', htmlspecialchars((string) $lotUrl));
        }

        return implode("\n", $lines);
    }

    private function ruInsurance(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return 'страховой';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return 'страховых';
        }

        return 'страховых';
    }

    private function ruOwners(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return 'владелец';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return 'владельца';
        }

        return 'владельцев';
    }

    private function ruLots(int $n): string
    {
        $mod10  = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) return 'новый лот';
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) return 'новых лота';
        return 'новых лотов';
    }

    public function notifyNewLots(int|string $chatId, string $label, array $lots, ?int $subscriptionId = null, array $subscriptionQuery = []): void
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
            $deepLink = $miniAppUrl;
            if (!empty($subscriptionQuery)) {
                $deepLink .= '?search=' . urlencode(json_encode($subscriptionQuery));
            }
            $footerButtons[] = [['text' => '📱 Все результаты', 'web_app' => ['url' => $deepLink]]];
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
