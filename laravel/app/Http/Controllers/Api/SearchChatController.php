<?php

namespace App\Http\Controllers\Api;

use App\Bot\ConversationState;
use App\Http\Controllers\Controller;
use App\Services\ChatSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchChatController extends Controller
{
    public function __construct(private readonly ChatSearchService $chatSearch) {}

    /**
     * POST /api/search-chat
     *
     * Body: { text: string, user_id: int|string, init_data: string, current?: object (the filters now) }
     *
     * Response:
     *   ok + data: { query: object, message: string, refined: bool }
     *   ok + data: { query: null,   message: string, refined: false }  — parse failed
     */
    public function chat(Request $request): JsonResponse
    {
        $text   = trim((string) $request->input('text', ''));
        $userId = $request->input('user_id', 0);

        if ($text === '') {
            return response()->json(['ok' => false, 'error' => 'text is required'], 422);
        }

        // the mini app sends its filters: the words refine them ("+ панорамный люк"), as the bot
        // refines its last search; no filters set is a new search
        $prevState = ConversationState::get($userId);
        if ($request->has('current')) {
            $current = array_filter((array) $request->input('current', []),
                fn ($v, $k) => $v !== null && $v !== '' && $v !== [] && !in_array($k, ['sort', 'limit', 'offset'], true),
                ARRAY_FILTER_USE_BOTH);
            $prevState = $current === [] ? null : ['query' => $current, 'total' => 0, 'description' => ''];
        }
        $parsed = $this->chatSearch->parseAndSearch($text, $prevState);

        if ($parsed === null) {
            return response()->json(['ok' => true, 'data' => [
                'query'   => null,
                'message' => 'Не удалось распознать запрос. Попробуйте переформулировать.',
                'refined' => false,
            ]]);
        }

        $query       = $parsed['query'];
        $description = $parsed['description'];
        $isRefined   = $parsed['refined'] ?? false;

        // Save state for subsequent refinements ("подешевле", "без ДТП", etc.)
        ConversationState::save($userId, $query->toSearchArray(), 0, $description);

        return response()->json(['ok' => true, 'data' => [
            'query'   => $query->toSearchArray(),
            'message' => $description,
            'refined' => $isRefined,
        ]]);
    }

    /**
     * POST /api/search-chat/reset
     *
     * Clears conversation state so next request starts fresh.
     */
    public function reset(Request $request): JsonResponse
    {
        ConversationState::clear($request->input('user_id', 0));

        return response()->json(['ok' => true]);
    }
}
