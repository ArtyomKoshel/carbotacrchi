<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\ProviderAggregator;
use App\Services\SearchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionsController extends Controller
{
    public function __construct(private readonly ProviderAggregator $aggregator) {}

    public function index(Request $request): JsonResponse
    {
        $subs = Subscription::where('user_id', $request->input('user_id', 0))
            ->active()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($s) => [
                'id'              => $s->id,
                'label'           => $s->label(),
                'query'           => $s->query,
                'new_lots_count'  => $s->new_lots_count ?? 0,
                'new_lot_previews'=> $s->new_lot_previews ?? [],
                'last_checked_at' => $s->last_checked_at?->toISOString(),
                'created_at'      => $s->created_at->toISOString(),
            ]);

        return response()->json(['ok' => true, 'data' => $subs]);
    }

    private function normalizeQuery(array $q): array
    {
        $query = SearchQuery::fromArray($q);
        $normalized = $query->toSearchArray();

        if (isset($normalized['sources']) && is_array($normalized['sources'])) {
            sort($normalized['sources']);
        }

        return $normalized;
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $request->input('user_id', 0);
        $normalized = $this->normalizeQuery($request->input('query', []));
        $query = $normalized;
        $normalizedJson = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $existing = Subscription::where('user_id', $userId)
            ->active()
            ->whereRaw('JSON_CONTAINS(`query`, CAST(? AS JSON))', [$normalizedJson])
            ->whereRaw('JSON_CONTAINS(CAST(? AS JSON), `query`)', [$normalizedJson])
            ->first();

        if ($existing) {
            return response()->json(['ok' => true, 'data' => [
                'id'      => $existing->id,
                'created' => false,
                'label'   => $existing->label(),
            ]]);
        }

        $searchQuery = SearchQuery::fromArray($query);
        $result = $this->aggregator->search($searchQuery);

        $sub = Subscription::create([
            'user_id'        => $userId,
            'query'          => $query,
            'last_checked_at'=> now(),
            'active'         => true,
        ]);

        $sub->markLotsAsSeen(array_map(fn ($lot) => $lot->id, $result->lots));

        return response()->json(['ok' => true, 'data' => [
            'id'      => $sub->id,
            'created' => true,
            'label'   => $sub->label(),
        ]], 201);
    }

    public function markSeen(Request $request, int $id): JsonResponse
    {
        Subscription::where('id', $id)
            ->where('user_id', $request->input('user_id', 0))
            ->update(['new_lots_count' => 0, 'new_lot_previews' => null]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        Subscription::where('id', $id)
            ->where('user_id', $request->input('user_id', 0))
            ->update(['active' => false]);

        return response()->json(['ok' => true, 'data' => ['removed' => true]]);
    }
}
