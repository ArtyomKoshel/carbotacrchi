<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyTerm;

class TaxonomyTermService
{
    /**
     * @return array{tail_powertrain_tokens: array<int, string>, package_hints: array<int, string>, trim_hints: array<int, string>}
     */
    public function getSets(string $source = 'encar'): array
    {
        $rows = TaxonomyTerm::query()
            ->where('is_active', true)
            ->where(function ($q) use ($source) {
                $q->where('source', $source)->orWhere('source', '*');
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get(['term_type', 'term']);

        $tokens = [];
        $packages = [];
        $trims = [];

        foreach ($rows as $row) {
            $term = trim((string) $row->term);
            if ($term === '') {
                continue;
            }
            if ($row->term_type === 'tail_powertrain_token') {
                $tokens[] = $term;
            } elseif ($row->term_type === 'package_hint') {
                $packages[] = $term;
            } elseif ($row->term_type === 'trim_hint') {
                $trims[] = $term;
            }
        }

        usort($packages, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        usort($trims, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return [
            'tail_powertrain_tokens' => array_values(array_unique($tokens)),
            'package_hints' => array_values(array_unique($packages)),
            'trim_hints' => array_values(array_unique($trims)),
        ];
    }
}
