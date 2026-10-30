<?php

namespace App\Support;

/**
 * A lot's price is in its platform's own currency (lots.currency): Encar in KRW, che168 in CNY.
 * Nothing is converted for showing or searching; dollars are only the calculators' business.
 */
final class Currency
{
    public const DEFAULT = 'KRW';

    private const SYMBOLS = ['KRW' => '₩', 'CNY' => '¥'];

    /** Platforms that do not price in KRW. */
    private const BY_SOURCE = ['che168' => 'CNY'];

    /** The currency a platform's prices (and its price rules) are in. */
    public static function of(?string $source): string
    {
        return self::BY_SOURCE[$source] ?? self::DEFAULT;
    }

    public static function symbol(?string $currency): string
    {
        $currency = $currency ?: self::DEFAULT;

        return self::SYMBOLS[$currency] ?? $currency;
    }

    /**
     * The one currency these platforms share, or null when they price in different ones
     * (a price range or a price order across them means nothing).
     *
     * @param  list<string>  $sources
     */
    public static function shared(array $sources): ?string
    {
        $currencies = array_values(array_unique(array_map(self::of(...), $sources)));

        return count($currencies) === 1 ? $currencies[0] : null;
    }
}
