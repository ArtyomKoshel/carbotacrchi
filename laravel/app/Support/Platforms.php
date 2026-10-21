<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Platforms (lot sources) the admin shows: every source that has lots or
 * parse jobs, plus the ones the parser is configured for. One place for the
 * list, its labels and lot links until more platforms arrive.
 */
class Platforms
{
    /** @return list<string> sources, configured ones first */
    public static function all(): array
    {
        return once(function () {
            $configured = array_values(array_filter((array) config('auction.sources', ['encar']), 'is_string'));
            // DISTINCT on the leading column of lots_source_active_parsed_idx: a loose index scan
            $inData = DB::table('lots')->distinct()->pluck('source')
                ->merge(DB::table('parse_jobs')->distinct()->pluck('source'))
                ->all();

            return array_values(array_unique(array_filter([...$configured, ...$inData])));
        });
    }

    public static function exists(string $source): bool
    {
        return in_array($source, self::all(), true);
    }

    public static function label(string $source): string
    {
        return AdminUiLabels::source($source);
    }

    public static function lotUrl(string $source, string $id, ?string $lotUrl = null): ?string
    {
        return LotLinks::platformUrl($source, $id, $lotUrl);
    }
}
