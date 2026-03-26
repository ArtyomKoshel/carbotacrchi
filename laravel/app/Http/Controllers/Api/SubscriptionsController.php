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
        $sources = $q['sources'] ?? [];
        sort($sources);

        return array_filter([
            'make'     => trim((string) ($q['make']     ?? '')),
            'model'    => trim((string) ($q['model']    ?? '')),
            'yearFrom' => (int) ($q['yearFrom'] ?? 0),
            'yearTo'   => (int) ($q['yearTo']   ?? 0),
            'priceMax' => (int) ($q['priceMax'] ?? 0),
            'sources'  => $sources,
        ], fn ($v) => $v !== '' && $v !== 0 && $v !== []);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $request->input('user_id', 0);
        $normalized = $this->normalizeQuery($request->input('query', []));
        $query = $normalized;

        $existing = Subscription::where('user_id', $userId)
            ->active()
            ->where('query', json_encode($normalized))
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
        $knownIds = array_map(fn ($lot) => $lot->id, $result->lots);

        $sub = Subscription::create([
            'user_id'        => $userId,
            'query'          => $query,
            'known_lot_ids'  => $knownIds,
            'last_checked_at'=> now(),
            'active'         => true,
        ]);

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
