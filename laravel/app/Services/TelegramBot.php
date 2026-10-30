<?php

namespace App\Services;

use App\Catalog\CatalogPlaces;
use App\Models\BotFilterSetting;
use App\Support\Currency;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBot
{
    private string $token;
    private string $apiBase;

    public function __construct(string $token, string $apiBase = 'https://api.telegram.org/bot')
    {
        $this->token   = $token;
        $this->apiBase = $apiBase;
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    /** Strip the bot token from a message (exception texts quote the request URL). */
    public function redact(string $message): string
    {
        return $this->token !== '' ? str_replace($this->token, '***', $message) : $message;
    }

    public function sendMessage(int|string $chatId, string $text, array $extra = []): array
    {
        return $this->request('sendMessage', array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $extra));
    }

    public function setChatMenuButton(int|string $chatId, string $text, string $url): array
    {
        return $this->request('setChatMenuButton', [
            'chat_id'     => $chatId,
            'menu_button' => json_encode([
                'type'    => 'web_app',
                'text'    => $text,
                'web_app' => ['url' => $url],
            ]),
        ]);
    }

    public function sendMessageWithKeyboard(int|string $chatId, string $text, array $inlineKeyboard): array
    {
        return $this->sendMessage($chatId, $text, [
            'reply_markup' => json_encode([
                'inline_keyboard' => $inlineKeyboard,
            ]),
        ]);
    }

    /**
     * A message the user then sends from the mini app with WebApp.shareMessage (Bot API 8.0):
     * Telegram's own chat picker, the bot never learns who received it.
     */
    public function savePreparedInlineMessage(int $userId, string $photoUrl, string $caption): array
    {
        return $this->request('savePreparedInlineMessage', [
            'user_id' => $userId,
            'result' => json_encode([
                'type' => 'photo',
                'id' => substr(sha1($photoUrl.$caption), 0, 32),
                'photo_url' => $photoUrl,
                'thumbnail_url' => $photoUrl,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'allow_user_chats' => true,
            'allow_group_chats' => true,
            'allow_channel_chats' => true,
        ]);
    }

    /** Up to 10 photos as one album, the caption under the first. */
    public function sendMediaGroup(int|string $chatId, array $photoUrls, string $caption): array
    {
        $media = [];
        foreach (array_slice(array_values($photoUrls), 0, 10) as $i => $url) {
            $media[] = ['type' => 'photo', 'media' => $url] + ($i === 0 ? ['caption' => $caption, 'parse_mode' => 'HTML'] : []);
        }

        return $this->request('sendMediaGroup', [
            'chat_id' => $chatId,
            'media' => json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

        $lines = [];

        // names from the reference catalog; Encar's own Korean ones only for a lot not on it
        $place = array_key_exists('catalog', $lot)
            ? $lot['catalog']
            : (isset($lot['id']) ? (app(CatalogPlaces::class)->of([(string) $lot['id']])[(string) $lot['id']] ?? null) : null);
        $make = $place['make'] ?? ($lot['make'] ?? null);
        $model = $place ? trim(($place['model'] ?? '') . ' ' . ($place['modification'] ?? '')) : ($lot['model'] ?? null);

        $titleParts = [];
        if (in_array('make', $cardFields, true) && !empty($make)) {
            $titleParts[] = htmlspecialchars((string) $make);
        }
        if (in_array('model', $cardFields, true) && !empty($model)) {
            $titleParts[] = htmlspecialchars((string) $model);
        }
        if (in_array('year', $cardFields, true) && !empty($lot['year'])) {
            $titleParts[] = (string) ((int) $lot['year']);
        }
        $title = trim(implode(' ', $titleParts));
        $lotUrl = $lot['lotUrl'] ?? '#';
        // The car name itself opens the lot — the "Открыть лот" line alone is a tiny tap target
        if ($title !== '' && $lotUrl !== '#') {
            $title = sprintf('<a href="%s">%s</a>', htmlspecialchars((string) $lotUrl), $title);
        }
        $source = in_array('source', $cardFields, true) && !empty($lot['sourceName'])
            ? htmlspecialchars((string) $lot['sourceName'])
            : '';

        if ($title !== '' && $source !== '') {
            $lines[] = sprintf('🚗 <b>%s</b> · %s', $title, $source);
        } elseif ($title !== '') {
            $lines[] = sprintf('🚗 <b>%s</b>', $title);
        } elseif ($source !== '') {
            $lines[] = sprintf('🚗 %s', $source);
        }

        $priceMileageParts = [];
        if (in_array('price', $cardFields, true)) {
            $priceRaw = $lot['price'] ?? null;
            if ($priceRaw !== null && $priceRaw !== '') {
                $price = number_format((int) $priceRaw, 0, '.', ',');
                $priceMileageParts[] = sprintf('💰 <b>%s%s</b>', Currency::symbol($lot['currency'] ?? null), $price);
            }
        }
        if (in_array('mileage', $cardFields, true)) {
            $kmRaw = $lot['mileage'] ?? null;
            if ($kmRaw !== null && $kmRaw !== '') {
                $km = number_format((int) $kmRaw, 0, '.', ',');
                $priceMileageParts[] = sprintf('🛣 %s km', $km);
            }
        }
        if ($priceMileageParts) {
            $lines[] = implode(' · ', $priceMileageParts);
        }

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
        if (in_array('body_type', $cardFields, true) && !empty($lot['bodyType'])) {
            $specs[] = htmlspecialchars((string) $lot['bodyType']);
        }
        $trimLabel = $place ? ($place['complectation'] ?? null) : ($lot['trim'] ?? null);
        if (in_array('trim', $cardFields, true) && !empty($trimLabel)) {
            $specs[] = '🏷 ' . htmlspecialchars((string) $trimLabel);
        }
        if (in_array('generation', $cardFields, true) && !empty($place['generation'])) {
            $specs[] = '🧬 ' . htmlspecialchars((string) $place['generation']);
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

        if (in_array('location', $cardFields, true) && !empty($lot['location'])) {
            $lines[] = '📍 ' . htmlspecialchars((string) $lot['location']);
        }

        if (in_array('lot_url', $cardFields, true) && $lotUrl !== '#') {
            $lines[] = sprintf('🔗 <a href="%s">Открыть лот</a>', htmlspecialchars((string) $lotUrl));
        }

        return $lines ? implode("\n", $lines) : "\u{200B}";
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
                $separator = str_contains($miniAppUrl, '?') ? '&' : '?';
                $payload = json_encode($subscriptionQuery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $deepLink .= $separator . 'search=' . rawurlencode($payload ?: '{}');
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

            $json = $response->json() ?? [];
            if (empty($json['ok'])) {
                Log::warning('[TelegramBot] '.$method.' returned error: '
                    .($json['description'] ?? $json['error'] ?? 'unknown')
                    .' (code: '.($json['error_code'] ?? '?').')');
            }
            return $json;
        } catch (\Throwable $e) {
            $error = $this->redact($e->getMessage());
            Log::error('[TelegramBot] '.$method.' failed: '.$error);
            return ['ok' => false, 'error' => $error];
        }
    }
}
