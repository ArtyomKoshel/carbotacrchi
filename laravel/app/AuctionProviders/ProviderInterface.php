<?php

namespace App\AuctionProviders;

use App\Dto\LotDTO;
use App\Services\SearchQuery;

interface ProviderInterface
{
    public function getKey(): string;
    public function getName(): string;
    public function isAvailable(): bool;

    /** @return LotDTO[] */
    public function search(SearchQuery $query): array;

    public function fetchRaw(SearchQuery $query): array;
    public function normalize(array $raw): LotDTO;
}
