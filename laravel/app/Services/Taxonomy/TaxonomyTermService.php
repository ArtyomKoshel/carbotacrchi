<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyTerm;

class TaxonomyTermService
{
    /**
     * @return array{
     *   tail_powertrain_tokens: array<int, string>,
     *   package_hints: array<int, string>,
     *   trim_hints: array<int, string>,
     *   engine_family_tokens: array<int, string>,
     *   gen_non_chassis_tokens: array<int, string>,
     *   gen_exclude_tokens: array<int, string>,
     *   variant_exclude: array<int, string>,
     *   special_tags: array<int, string>
     * }
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
        $engineFamily = [];
        $genNonChassis = [];
        $genExclude = [];
        $variantExclude = [];
        $specialTags = [];

        foreach ($rows as $row) {
            $term = trim((string) $row->term);
            if ($term === '') {
                continue;
            }
            match ($row->term_type) {
                'tail_powertrain_token'  => $tokens[]       = $term,
                'package_hint'           => $packages[]     = $term,
                'trim_hint'              => $trims[]        = $term,
                'engine_family_tokens'   => $engineFamily[] = $term,
                'gen_non_chassis_token'  => $genNonChassis[]= $term,
                'gen_exclude_token'      => $genExclude[]   = $term,
                'variant_exclude'        => $variantExclude[]= $term,
                'special_tag'            => $specialTags[]  = $term,
                default                  => null,
            };
        }

        usort($packages, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        usort($trims, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return [
            'tail_powertrain_tokens' => array_values(array_unique($tokens)),
            'package_hints'          => array_values(array_unique($packages)),
            'trim_hints'             => array_values(array_unique($trims)),
            'engine_family_tokens'   => array_values(array_unique($engineFamily)),
            'gen_non_chassis_tokens' => array_values(array_unique($genNonChassis)),
            'gen_exclude_tokens'     => array_values(array_unique($genExclude)),
            'variant_exclude'        => array_values(array_unique($variantExclude)),
            'special_tags'           => array_values(array_unique($specialTags)),
        ];
    }
}
