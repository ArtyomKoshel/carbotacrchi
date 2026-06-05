<?php

namespace App\Services;

use App\Models\Lot;
use Illuminate\Support\Facades\Log;

class LotSearchService
{
    /**
     * Full-text search via Meilisearch (falls back to DB LIKE if Scout unavailable).
     *
     * @return array{ids: string[], total: int}
     */
    public function search(string $query, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        if (!$this->isAvailable()) {
            return $this->fallbackSearch($query, $filters, $limit, $offset);
        }

        try {
            $builder = Lot::search($query);

            // Apply structured filters on top of full-text
            if (!empty($filters['source'])) {
                $builder->where('source', $filters['source']);
            }
            if (isset($filters['is_active'])) {
                $builder->where('is_active', (bool) $filters['is_active']);
            }
            if (!empty($filters['make'])) {
                $builder->where('make', $filters['make']);
            }

            $results = $builder
                ->options([
                    'limit'  => $limit,
                    'offset' => $offset,
                ])
                ->get();

            return [
                'ids'   => $results->pluck('id')->all(),
                'total' => $results->count(),
            ];
        } catch (\Throwable $e) {
            Log::warning('[LotSearch] Meilisearch error, falling back to DB: ' . $e->getMessage());
            return $this->fallbackSearch($query, $filters, $limit, $offset);
        }
    }

    private function isAvailable(): bool
    {
        return config('scout.driver') === 'meilisearch'
            && config('scout.meilisearch.host') !== '';
    }

    private function fallbackSearch(string $query, array $filters, int $limit, int $offset): array
    {
        $terms = array_filter(explode(' ', $query));
        if (empty($terms)) {
            return ['ids' => [], 'total' => 0];
        }

        $builder = \Illuminate\Support\Facades\DB::table('lots')
            ->where('is_active', true);

        if (!empty($filters['source'])) {
            $builder->where('source', $filters['source']);
        }

        foreach ($terms as $term) {
            $like = '%' . $term . '%';
            $builder->where(function ($q) use ($like): void {
                $q->where('make',             'like', $like)
                  ->orWhere('model',          'like', $like)
                  ->orWhere('model_en',       'like', $like)
                  ->orWhere('model_group_en', 'like', $like)
                  ->orWhere('trim',           'like', $like);
            });
        }

        $total = (clone $builder)->count();
        $ids   = $builder->orderByDesc('listed_at')->offset($offset)->limit($limit)->pluck('id')->all();

        return ['ids' => $ids, 'total' => $total];
    }
}
