<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Search;
use App\Services\OptionEnricher;
use App\Services\ProviderAggregator;
use App\Services\QueryExpansionService;
use App\Services\SearchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        private readonly ProviderAggregator    $aggregator,
        private readonly OptionEnricher        $enricher,
        private readonly QueryExpansionService $expander,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $query       = SearchQuery::fromArray($request->input('query', []));
        $searchQuery = $query->withBotTolerance();

        $result = $this->aggregator->search($searchQuery);

        $relaxed        = false;
        $relaxedNotes   = [];
        $relaxedMessage = '';

        if ($result->total === 0) {
            $expansion = $this->expander->expand($searchQuery);
            if ($expansion->result->total > 0) {
                $result         = $expansion->result;
                $relaxed        = true;
                $relaxedNotes   = $expansion->notes;
                $relaxedMessage = $expansion->relaxedMessage();
            }
        }

        try {
            Search::create([
                'user_id'     => $request->input('user_id', 0),
                'make'        => $query->make    ?: null,
                'model'       => $query->model   ?: null,
                'year_from'   => $query->yearFrom ?: null,
                'year_to'     => $query->yearTo   ?: null,
                'price_max'   => $query->priceMax ?: null,
                'sources'     => $query->sources,
                'query'       => $query->toSearchArray() ?: null,
                'results_cnt' => $result->total,
                'relaxed'     => $relaxed,
            ]);
        } catch (\Throwable) {
        }

        $data                   = $result->toArray();
        $data['lots']           = $this->enricher->enrichLots($data['lots']);
        $data['relaxed']        = $relaxed;
        $data['relaxedNotes']   = $relaxedNotes;
        $data['relaxedMessage'] = $relaxedMessage;

        return response()->json(['ok' => true, 'data' => $data]);
    }
}
