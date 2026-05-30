<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Search;
use App\Services\OptionEnricher;
use App\Services\ProviderAggregator;
use App\Services\SearchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        private readonly ProviderAggregator $aggregator,
        private readonly OptionEnricher     $enricher,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $query = SearchQuery::fromArray($request->input('query', []));

        $searchQuery = $query->withBotTolerance();

        $result = $this->aggregator->search($searchQuery);

        try {
            Search::create([
                'user_id'     => $request->input('user_id', 0),
                'make'        => $query->make    ?: null,
                'model'       => $query->model   ?: null,
                'year_from'   => $query->yearFrom ?: null,
                'year_to'     => $query->yearTo   ?: null,
                'price_max'   => $query->priceMax ?: null,
                'sources'     => $query->sources,
                'results_cnt' => $result->total,
            ]);
        } catch (\Throwable) {
        }

        $data         = $result->toArray();
        $data['lots'] = $this->enricher->enrichLots($data['lots']);

        return response()->json(['ok' => true, 'data' => $data]);
    }
}
