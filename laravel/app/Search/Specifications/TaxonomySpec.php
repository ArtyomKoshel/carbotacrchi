<?php

namespace App\Search\Specifications;

use App\Services\SearchQuery;
use App\Support\Taxonomy\TaxonomyNormalizer;
use Illuminate\Database\Query\Builder;

class TaxonomySpec implements LotSpecification
{
    public function apply(Builder $query, SearchQuery $search): void
    {
        $this->applyEnum($query, $search->transmissions, 'transmission');
        $this->applyEnum($query, $search->fuelTypes,     'fuel');
        $this->applyEnum($query, $search->bodyTypes,     'body_type');
        $this->applyEnum($query, $search->driveTypes,    'drive_type');

        if ($search->colors)         $query->whereIn('color',         $search->colors);
        if ($search->lienStatuses)   $query->whereIn('lien_status',   $search->lienStatuses);
        if ($search->seizureStatuses) $query->whereIn('seizure_status', $search->seizureStatuses);
        if ($search->sellTypes)      $query->whereIn('sell_type',     $search->sellTypes);
    }

    private function applyEnum(Builder $query, array $values, string $column): void
    {
        if (empty($values)) {
            return;
        }
        $expanded = TaxonomyNormalizer::expandManyToDbValues($column, $values);
        if ($expanded !== []) {
            $query->whereIn($column, $expanded);
        }
    }
}
