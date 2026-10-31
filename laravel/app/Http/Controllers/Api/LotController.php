<?php

namespace App\Http\Controllers\Api;

use App\Catalog\CatalogPlaces;
use App\Catalog\Che168\ChaboshiReport;
use App\Http\Controllers\Controller;
use App\Models\LotInspection;
use App\Models\User;
use App\Services\OptionEnricher;
use App\Services\ProviderAggregator;
use App\Services\TelegramBot;
use App\Support\ClientLotCard;
use App\Support\LotPhotos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** One lot by id (its own link in the mini app) and sending it to a client. */
class LotController extends Controller
{
    public function __construct(
        private readonly ProviderAggregator $aggregator,
        private readonly OptionEnricher $enricher,
    ) {}

    public function show(string $lotId): JsonResponse
    {
        $lot = $this->lot($lotId);
        if ($lot === null) {
            return response()->json(['ok' => false, 'error' => 'Лот не найден или снят с продажи'], 404);
        }

        return response()->json(['ok' => true, 'data' => ['lot' => $lot, 'inspection' => $this->inspection($lotId)]]);
    }

    /**
     * mode=prepare (Telegram): a prepared message for WebApp.shareMessage; when Telegram refuses
     * it, the card goes to the picker's own chat instead. mode=chat: to the picker's chat.
     * mode=text: only the text, for the browser's copy button.
     */
    public function share(Request $request, TelegramBot $bot, string $lotId): JsonResponse
    {
        $lot = $this->lot($lotId);
        if ($lot === null) {
            return response()->json(['ok' => false, 'error' => 'Лот не найден или снят с продажи'], 404);
        }
        $inspection = $this->inspection($lotId);
        $mode = (string) $request->input('mode', 'text');
        $userId = (int) $request->input('user_id'); // set by ValidateTelegramAuth from the signed data
        $username = $userId > 0 ? (User::find($userId)?->username ?? '') : '';

        $calc = self::calcLines($request->input('calc'));
        $text = ClientLotCard::text($lot, $inspection, $username, $calc);

        // For Telegram, use raw URLs (no impolicy) to avoid CDN watermark overlay; limit to 7 photos
        $rawPhotos = $lot['photos'] ? LotPhotos::rawUrls(DB::table('lots')->where('id', $lotId)->first() ?? []) : [];
        if (! $rawPhotos && $lot['imageUrl']) {
            $rawPhotos = [(string) strtok((string) $lot['imageUrl'], '?')];
        }
        $telegramPhotos = array_slice($rawPhotos, 0, 7);

        if ($mode === 'text' || $userId <= 0 || ! $bot->isConfigured()) {
            return response()->json(['ok' => true, 'data' => ['text' => $text]]);
        }

        $caption = ClientLotCard::html($lot, $inspection, $username, $calc);
        if ($mode === 'prepare' && $telegramPhotos) {
            $prepared = $bot->savePreparedInlineMessage($userId, $telegramPhotos[0], $caption);
            if (! empty($prepared['ok']) && ! empty($prepared['result']['id'])) {
                return response()->json(['ok' => true, 'data' => ['prepared_message_id' => $prepared['result']['id'], 'text' => $text]]);
            }
        }

        $sent = $telegramPhotos ? $bot->sendMediaGroup($userId, $telegramPhotos, $caption) : $bot->sendMessage($userId, $caption);

        return response()->json(['ok' => ! empty($sent['ok']), 'data' => ['sent_to_chat' => ! empty($sent['ok']), 'text' => $text]]);
    }

    /**
     * The mini app's landed-cost lines (Calc.shareLines) as plain text: at most 12 lines of
     * 160 characters, no control characters; '' is a blank line (one at a time, none at the
     * ends). Anything else is dropped.
     *
     * @return list<string>
     */
    private static function calcLines(mixed $calc): array
    {
        if (! is_array($calc)) {
            return [];
        }
        $lines = [];
        foreach (array_slice(array_values($calc), 0, 12) as $line) {
            $line ??= ''; // the request turns a blank line into null
            if (! is_string($line)) {
                continue;
            }
            $clean = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $line)), 0, 160);
            if ($clean !== '' || ($lines !== [] && end($lines) !== '')) {
                $lines[] = $clean;
            }
        }
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /** @return array<string, mixed>|null */
    private function lot(string $lotId): ?array
    {
        $row = DB::table('lots')->where('id', $lotId)->first();
        $provider = $row ? $this->aggregator->provider((string) $row->source) : null;
        if ($row === null || $provider === null) {
            return null;
        }
        $lot = $provider->normalize((array) $row)->toArray();
        $lot['isActive'] = (bool) $row->is_active;
        $lot['catalog'] = app(CatalogPlaces::class)->of([$lotId])[$lotId] ?? null;

        return $this->enricher->enrichLots([$lot])[0];
    }

    /**
     * The lot's inspection row; a Che168 lot's Chaboshi report also comes described in Russian
     * (`chaboshi`), the card shows it instead of Encar's inspection rows.
     *
     * @return array<string, mixed>|null
     */
    private function inspection(string $lotId): ?array
    {
        $inspection = LotInspection::where('lot_id', $lotId)->first()?->toArray();
        $report = $inspection['details']['chaboshi'] ?? null;
        if (is_array($report)) {
            $inspection['chaboshi'] = app(ChaboshiReport::class)->describe($report);
        }

        return $inspection;
    }
}
