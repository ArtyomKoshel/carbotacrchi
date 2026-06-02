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
     * Body: { text: string, user_id: int|string, init_data: string }
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

        $prevState = ConversationState::get($userId);
        $parsed    = $this->chatSearch->parseAndSearch($text, $prevState);

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
