<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * Provides catalog data helpers used by AI classification commands.
 *
 * NOTE: The grade-exact-match lookup (lookup() / catalog_grades) was removed —
 * the table is kept for future use but the lookup path was dead code.
 * The grade extraction pipeline uses GradeExtractorService instead.
 */
class CatalogLookupService
{
    /** @var array<string, array>|null  token_type → [token => mapped_value|true] */
    private ?array $tokenMapsCache = null;

    /**
     * Returns all token maps loaded from catalog_token_maps.
     * Structure: ['fuel' => ['디젤' => 'diesel', ...], 'drive' => [...], 'body' => [...],
     *             'variant_whitelist' => [...], 'model_body_hint' => [...], ...]
     *
     * @return array<string, array<string, string|true>>
     */
    public function tokenMaps(): array
    {
        if ($this->tokenMapsCache !== null) {
            return $this->tokenMapsCache;
        }

        $this->tokenMapsCache = [];
        $rows = DB::table('catalog_token_maps')
            ->get(['token', 'token_type', 'mapped_value'])
            ->toArray();

        foreach ($rows as $r) {
            $this->tokenMapsCache[$r->token_type][$r->token] =
                $r->mapped_value !== null ? $r->mapped_value : true;
        }

        return $this->tokenMapsCache;
    }
}
