<?php

namespace App\Services;

class QueryExpansionService
{
    public function __construct(private readonly ProviderAggregator $aggregator) {}

    /**
     * If the original query returns 0 results, progressively relax constraints
     * (from most specific to least specific) until something is found.
     * Returns the first relaxed query that yields results, with notes describing
     * what was changed.
     */
    public function expand(SearchQuery $query): ExpansionResult
    {
        $current = clone $query;
        $current->offset = 0;
        $notes = [];

        foreach ($this->buildSteps() as [$isApplicable, $transform, $label]) {
            if (!$isApplicable($current)) {
                continue;
            }

            $candidate = $transform($current);
            $notes[]   = $label;
            $count     = $this->aggregator->count($candidate);
            $current   = $candidate;

            if ($count > 0) {
                $result = $this->aggregator->search($current);
                return ExpansionResult::relaxed($result, $notes);
            }
        }

        return ExpansionResult::relaxed(new SearchResult([], 0, []), $notes);
    }

    /**
     * @return array{callable, callable, string}[]
     *   Each step: [isApplicable(SearchQuery): bool, transform(SearchQuery): SearchQuery, label]
     */
    private function buildSteps(): array
    {
        return [
            [fn ($q) => $q->trim !== '',              fn ($q) => $this->drop($q, 'trim', ''),               'комплектация'],
            [fn ($q) => !empty($q->options),           fn ($q) => $this->drop($q, 'options', []),             'доп. опции'],
            [fn ($q) => $q->generation !== '',         fn ($q) => $this->drop($q, 'generation', ''),          'поколение'],
            [fn ($q) => !empty($q->colors),            fn ($q) => $this->drop($q, 'colors', []),              'цвет'],
            [fn ($q) => $q->mileageMax > 0,            fn ($q) => $this->relaxMileage($q, 1.3),               'пробег расширен +30%'],
            [fn ($q) => $q->yearFrom > 0,              fn ($q) => $this->relaxYear($q, 2),                    'год выпуска расширен'],
            [fn ($q) => $q->priceMax > 0,              fn ($q) => $this->relaxPrice($q, 1.2),                 'цена расширена +20%'],
            [fn ($q) => !empty($q->bodyTypes),         fn ($q) => $this->drop($q, 'bodyTypes', []),           'тип кузова'],
            [fn ($q) => !empty($q->transmissions),     fn ($q) => $this->drop($q, 'transmissions', []),       'КПП'],
            [fn ($q) => !empty($q->fuelTypes),         fn ($q) => $this->drop($q, 'fuelTypes', []),           'топливо'],
            [fn ($q) => !empty($q->driveTypes),        fn ($q) => $this->drop($q, 'driveTypes', []),          'привод'],
        ];
    }

    private function drop(SearchQuery $q, string $field, mixed $empty): SearchQuery
    {
        $clone = clone $q;
        $clone->$field = $empty;
        return $clone;
    }

    private function relaxMileage(SearchQuery $q, float $factor): SearchQuery
    {
        $clone = clone $q;
        if ($clone->mileageMax > 0) {
            $clone->mileageMax = (int) round($clone->mileageMax * $factor);
        }
        if ($clone->mileageMin > 0) {
            $clone->mileageMin = (int) round($clone->mileageMin / $factor);
        }
        return $clone;
    }

    private function relaxYear(SearchQuery $q, int $years): SearchQuery
    {
        $clone = clone $q;
        if ($clone->yearFrom > 0) {
            $clone->yearFrom = $clone->yearFrom - $years;
        }
        return $clone;
    }

    private function relaxPrice(SearchQuery $q, float $factor): SearchQuery
    {
        $clone = clone $q;
        if ($clone->priceMax > 0) {
            $clone->priceMax = (int) round($clone->priceMax * $factor);
        }
        return $clone;
    }
}
