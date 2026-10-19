<?php

namespace App\Support;

use App\Models\EncarOptionCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Option codes ↔ bits of `lots.options_mask`.
 *
 * Every code in `encar_option_catalog` gets a stable `bit`. Bits 0..63 live in
 * `lots.options_mask` (BIGINT UNSIGNED), so "has all of these options" is
 * `(options_mask & m) = m` on the lots row itself — no pivot table.
 * A code without a bit < 64 (unknown to the catalog, or the catalog outgrew
 * 64 codes) is still in `lots.options` JSON; search falls back to JSON for it.
 * When that becomes common, add `options_mask_2` for bits 64..127.
 *
 * Python mirror: parser/lot_compact.py (OptionBits).
 */
class OptionBits
{
    public const MASK_BITS = 64;

    /** @return array<string, int> code → bit */
    public static function map(): array
    {
        return EncarOptionCatalog::getCached()
            ->filter(fn ($opt) => $opt->bit !== null)
            ->map(fn ($opt) => (int) $opt->bit)
            ->all();
    }

    /**
     * Split codes into the mask they share and the codes the mask can't hold.
     *
     * @param  array<int, string|int> $codes
     * @return array{0: int, 1: list<string>}  [mask, codes without a mask bit]
     */
    public static function split(array $codes): array
    {
        $bits  = self::map();
        $mask  = 0;
        $rest  = [];
        foreach (array_unique(array_map(fn ($c) => trim((string) $c), $codes)) as $code) {
            if ($code === '') {
                continue;
            }
            $bit = $bits[$code] ?? null;
            if ($bit !== null && $bit < self::MASK_BITS) {
                $mask |= 1 << $bit;
            } else {
                $rest[] = $code;
            }
        }
        return [$mask, $rest];
    }

    /**
     * Mask value to store for a lot's options list (null = no options list).
     * Returned as an unsigned decimal string: bit 63 would be a negative PHP int.
     */
    public static function maskForStorage(mixed $codes): ?string
    {
        if (is_string($codes)) {
            $codes = json_decode($codes, true);
        }
        if (!is_array($codes) || $codes === []) {
            return null;
        }
        return self::unsigned(self::split($codes)[0]);
    }

    public static function unsigned(int $mask): string
    {
        return sprintf('%u', $mask);
    }

    /**
     * Give every catalog code without a bit the next free one (in code order)
     * and set that bit on lots that already carry the code.
     *
     * @return array<string, int> newly assigned code → bit
     */
    public static function assignMissing(): array
    {
        $assigned = [];
        $missing  = DB::table('encar_option_catalog')->whereNull('bit')->orderBy('code')->pluck('code');
        foreach ($missing as $code) {
            $max  = DB::table('encar_option_catalog')->max('bit');
            $next = $max === null ? 0 : (int) $max + 1;
            DB::table('encar_option_catalog')->where('code', $code)->whereNull('bit')->update(['bit' => $next]);
            $assigned[$code] = $next;
        }

        EncarOptionCatalog::flushCache();

        foreach ($assigned as $code => $bit) {
            if ($bit < self::MASK_BITS) {
                self::backfillBit($code, $bit);
            }
        }

        return $assigned;
    }

    /** Set `bit` on every lot whose options JSON has `code`, in PK chunks. */
    public static function backfillBit(string $code, int $bit, int $chunk = 5000): int
    {
        $flag    = self::unsigned(1 << $bit);
        $updated = 0;
        $last    = '';
        while (true) {
            $upper = DB::table('lots')->where('id', '>', $last)->orderBy('id')->offset($chunk - 1)->value('id');
            $updated += DB::update(
                'UPDATE lots SET options_mask = options_mask | CAST(? AS UNSIGNED)
                 WHERE id > ?' . ($upper !== null ? ' AND id <= ?' : '') . '
                   AND options_mask IS NOT NULL AND ? MEMBER OF (options)',
                array_values(array_filter([$flag, $last, $upper, $code], fn ($v) => $v !== null)),
            );
            if ($upper === null) {
                return $updated;
            }
            $last = $upper;
        }
    }
}
