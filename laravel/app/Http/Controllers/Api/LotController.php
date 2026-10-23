<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LotInspection;
use App\Services\OptionEnricher;
use App\Services\ProviderAggregator;
use App\Services\TelegramBot;
use App\Support\ClientLotCard;
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
        $text = ClientLotCard::text($lot, $inspection);
        $mode = (string) $request->input('mode', 'text');
        $userId = (int) $request->input('user_id'); // set by ValidateTelegramAuth from the signed data
        $photos = $lot['photos'] ?: array_filter([$lot['imageUrl']]);

        if ($mode === 'text' || $userId <= 0 || ! $bot->isConfigured()) {
            return response()->json(['ok' => true, 'data' => ['text' => $text]]);
        }

        $caption = ClientLotCard::html($lot, $inspection);
        if ($mode === 'prepare' && $photos) {
            $prepared = $bot->savePreparedInlineMessage($userId, $photos[0], $caption);
            if (! empty($prepared['ok']) && ! empty($prepared['result']['id'])) {
                return response()->json(['ok' => true, 'data' => ['prepared_message_id' => $prepared['result']['id'], 'text' => $text]]);
            }
        }

        $sent = $photos ? $bot->sendMediaGroup($userId, $photos, $caption) : $bot->sendMessage($userId, $caption);

        return response()->json(['ok' => ! empty($sent['ok']), 'data' => ['sent_to_chat' => ! empty($sent['ok']), 'text' => $text]]);
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
        $lot['catalog'] = $this->catalog($row);

        return $this->enricher->enrichLots([$lot])[0];
    }

    /**
     * The lot's place in the reference catalog (app/Catalog), null when it has none. Only what
     * the platform stated: fuel / drive of the modification are null unless its badge said them.
     *
     * @return array<string, mixed>|null
     */
    private function catalog(object $row): ?array
    {
        if (empty($row->ref_variant_id) || empty($row->ref_model_id)) {
            return null;
        }
        $place = DB::table('ref_variants as v')
            ->leftJoin('ref_models as m', 'm.id', '=', 'v.model_id')
            ->leftJoin('ref_generations as g', 'g.id', '=', 'v.generation_id')
            ->leftJoin('ref_modifications as d', 'd.id', '=', 'v.modification_id')
            ->leftJoin('ref_complectations as c', 'c.id', '=', 'v.complectation_id')
            ->where('v.id', $row->ref_variant_id)
            ->first(['m.name as model', 'g.code as generation', 'g.year_from', 'g.year_to', 'd.code as modification',
                'd.fuel', 'd.drive', 'c.name as complectation', 'v.editions', 'v.status']);
        if ($place === null) {
            return null;
        }
        $place = (array) $place;
        $place['editions'] = $place['editions'] ? json_decode($place['editions'], true) : [];

        return $place;
    }

    /** @return array<string, mixed>|null */
    private function inspection(string $lotId): ?array
    {
        return LotInspection::where('lot_id', $lotId)->first()?->toArray();
    }
}
