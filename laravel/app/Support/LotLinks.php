<?php

namespace App\Support;

/**
 * Link to a lot on its platform. Encar keeps the URL used by the old admin
 * (lots-browse) until a platform registry exists; other sources use the
 * lot_url the parser stored.
 */
class LotLinks
{
    public static function platformUrl(string $source, string $id, ?string $lotUrl = null): ?string
    {
        return match ($source) {
            'encar' => 'https://www.encar.com/dc/dc_cardetailview.do?carid=' . rawurlencode($id),
            default => $lotUrl ?: null,
        };
    }
}
