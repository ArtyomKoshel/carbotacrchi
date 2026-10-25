<?php

namespace App\AuctionProviders;

use App\Dto\LotDTO;

interface ProviderInterface
{
    public function getKey(): string;
    public function getName(): string;
    public function isAvailable(): bool;

    public function normalize(array $raw): LotDTO;
}
