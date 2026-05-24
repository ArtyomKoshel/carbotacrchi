<?php

namespace App\Services;

use App\AuctionProviders\ProviderInterface;
use App\Support\Taxonomy\TaxonomyNormalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ProviderAggregator
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    public function register(ProviderInterface ...$providers): static
    {
        foreach ($providers as $p) {
            $this->providers[$p->getKey()] = $p;
        }

        return $this;
    }

    public function search(SearchQuery $query): SearchResult
    {
        $errors = [];
        foreach ($query->sources as $sourceKey) {
            $provider = $this->providers[$sourceKey] ?? null;
            if ($provider === null || !$provider->isAvailable()) {
                $errors[] = (string) $sourceKey;
            }
        }
        $errors = array_values(array_unique($errors));

        $activeProviders = $this->getActiveProviders($query->sources);
        $providersByKey = [];
        foreach ($activeProviders as $provider) {
            $providersByKey[$provider->getKey()] = $provider;
        }

        if ($providersByKey === []) {
            return new SearchResult([], 0, $errors);
        }

        $builder = DB::table('lots')
            ->where('is_active', true)
            ->whereIn('source', array_keys($providersByKey));

        $this->applyDbFilters($builder, $query);

        $total = (clone $builder)->count();

        $this->applyDbSort($builder, $query->sort);

        $rows = $builder
            ->offset($query->offset)
            ->limit($query->limit)
            ->get();

        $lots = [];
        foreach ($rows as $row) {
            $raw = (array) $row;
            $source = (string) ($raw['source'] ?? '');
            $provider = $providersByKey[$source] ?? null;
            if ($provider === null) {
                continue;
            }

            $lots[] = $provider->normalize($raw);
        }

        return new SearchResult($lots, $total, $errors);
    }

    /** @return ProviderInterface[] */
    private function getActiveProviders(array $keys): array
    {
        return array_values(array_filter(
            array_map(fn ($k) => $this->providers[$k] ?? null, $keys),
            fn ($p) => $p !== null && $p->isAvailable()
        ));
    }

    private function applyDbSort(Builder $builder, string $sort): void
    {
        match ($sort) {
            'price_asc' => $builder->orderBy('price', 'asc'),
            'price_desc' => $builder->orderBy('price', 'desc'),
            default => $builder->orderBy('registration_date', 'desc')->orderBy('id', 'desc'),
        };
    }

    private function applyDbFilters(Builder $builder, SearchQuery $query): void
    {
        if ($query->make) {
            $variants = TaxonomyNormalizer::expandToDbValues('make', $query->make);
            $builder->where(function (Builder $sub) use ($variants, $query): void {
                if ($variants !== []) {
                    $sub->whereIn('make', $variants)
                        ->orWhereRaw('make LIKE ?', [$query->make . '%']);
                    return;
                }

                $sub->whereRaw('make LIKE ?', [$query->make . '%']);
            });
        }
        if ($query->model) {
            $builder->where(function (Builder $sub) use ($query): void {
                $sub->where('model', $query->model)->orWhere('model_en', $query->model);
            });
        }
        if ($query->generation) {
            $builder->whereRaw('generation LIKE ?', ['%' . $query->generation . '%']);
        }

        if ($query->yearFrom) {
            $builder->where('year', '>=', $query->yearFrom);
        }
        if ($query->yearTo) {
            $builder->where('year', '<=', $query->yearTo);
        }
        if ($query->priceMin) {
            $builder->where('price', '>=', $query->priceMin);
        }
        if ($query->priceMax) {
            $builder->where('price', '<=', $query->priceMax);
        }
        if ($query->mileageMin) {
            $builder->where('mileage', '>=', $query->mileageMin);
        }
        if ($query->mileageMax) {
            $builder->where('mileage', '<=', $query->mileageMax);
        }
        if ($query->engineMin) {
            $builder->where('engine_volume', '>=', $query->engineMin);
        }
        if ($query->engineMax) {
            $builder->where('engine_volume', '<=', $query->engineMax);
        }

        if ($query->repairCostMin) {
            $builder->where('repair_cost', '>=', $query->repairCostMin);
        }
        if ($query->repairCostMax) {
            $builder->where('repair_cost', '<=', $query->repairCostMax);
        }
        if ($query->retailValueMin) {
            $builder->where('retail_value', '>=', $query->retailValueMin);
        }
        if ($query->retailValueMax) {
            $builder->where('retail_value', '<=', $query->retailValueMax);
        }

        if ($query->ownersCountMin !== null) {
            $builder->where('owners_count', '>=', $query->ownersCountMin);
        }
        if ($query->ownersCountMax !== null) {
            $builder->where('owners_count', '<=', $query->ownersCountMax);
        }
        if ($query->insuranceCountMin !== null) {
            $builder->where('insurance_count', '>=', $query->insuranceCountMin);
        }
        if ($query->insuranceCountMax !== null) {
            $builder->where('insurance_count', '<=', $query->insuranceCountMax);
        }

        if ($query->seatCountMin) {
            $builder->where('seat_count', '>=', $query->seatCountMin);
        }
        if ($query->seatCountMax) {
            $builder->where('seat_count', '<=', $query->seatCountMax);
        }
        if ($query->registrationYearMonthMin) {
            $builder->where('registration_year_month', '>=', $query->registrationYearMonthMin);
        }
        if ($query->registrationYearMonthMax) {
            $builder->where('registration_year_month', '<=', $query->registrationYearMonthMax);
        }

        if ($query->hasAccident !== null) {
            $builder->where('has_accident', $query->hasAccident);
        }
        if ($query->floodHistory !== null) {
            $builder->where('flood_history', $query->floodHistory);
        }
        if ($query->totalLossHistory !== null) {
            $builder->where('total_loss_history', $query->totalLossHistory);
        }

        if ($query->transmissions) {
            $vals = TaxonomyNormalizer::expandManyToDbValues('transmission', $query->transmissions);
            if ($vals !== []) {
                $builder->whereIn('transmission', $vals);
            }
        }
        if ($query->fuelTypes) {
            $vals = TaxonomyNormalizer::expandManyToDbValues('fuel', $query->fuelTypes);
            if ($vals !== []) {
                $builder->whereIn('fuel', $vals);
            }
        }
        if ($query->bodyTypes) {
            $vals = TaxonomyNormalizer::expandManyToDbValues('body_type', $query->bodyTypes);
            if ($vals !== []) {
                $builder->whereIn('body_type', $vals);
            }
        }
        if ($query->driveTypes) {
            $vals = TaxonomyNormalizer::expandManyToDbValues('drive_type', $query->driveTypes);
            if ($vals !== []) {
                $builder->whereIn('drive_type', $vals);
            }
        }
        if ($query->colors) {
            $builder->whereIn('color', $query->colors);
        }
        if ($query->lienStatuses) {
            $builder->whereIn('lien_status', $query->lienStatuses);
        }
        if ($query->seizureStatuses) {
            $builder->whereIn('seizure_status', $query->seizureStatuses);
        }
        if ($query->sellTypes) {
            $builder->whereIn('sell_type', $query->sellTypes);
        }

        if ($query->trim) {
            $builder->where('trim', $query->trim);
        }
        if ($query->vin) {
            $builder->where('vin', $query->vin);
        }
    }
}
