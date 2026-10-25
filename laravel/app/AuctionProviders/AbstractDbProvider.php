<?php

namespace App\AuctionProviders;

/** A source whose lots the collector writes into `lots`; ProviderAggregator searches them. */
abstract class AbstractDbProvider extends AbstractProvider
{
    public function isAvailable(): bool
    {
        return true;
    }
}
