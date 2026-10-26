<?php

namespace App\Ingest;

use App\Support\Taxonomy\TaxonomyCatalog;

/**
 * Stored row vs incoming row → one `lot_changes` entry (or none), with the
 * old parser's tracked fields and diff format: {"field": {"old": …, "new": …}}.
 * A field counts only when the incoming value is not null (null keeps the
 * stored value, see LotUpsert).
 */
final class LotChangeDetector
{
    public const TRACKED = [
        'trim', 'price', 'mileage', 'color', 'lien_status', 'seizure_status', 'has_accident',
        'flood_history', 'total_loss_history', 'owners_count', 'insurance_count', 'sell_type', 'options',
        'is_active',
    ];

    /**
     * @param  array<string, mixed>|null  $old  stored tracked columns, null for a new lot
     * @param  array<string, mixed>  $new  incoming row
     * @return array{event: string, changes: array<string, array{old: mixed, new: mixed}>}|null
     */
    public static function detect(?array $old, array $new): ?array
    {
        if ($old === null) {
            return null; // a new lot has no history yet
        }
        $changes = [];
        foreach (self::TRACKED as $field) {
            if (! array_key_exists($field, $new) || $new[$field] === null) {
                continue;
            }
            $before = self::canonical($field, $old[$field] ?? null);
            $after = self::canonical($field, $new[$field]);
            if ($before !== $after) {
                $changes[$field] = ['old' => $before, 'new' => $after];
            }
        }
        if ($changes === []) {
            return null;
        }

        return ['event' => self::event($changes), 'changes' => $changes];
    }

    /** @param array<string, array{old: mixed, new: mixed}> $changes */
    private static function event(array $changes): string
    {
        if (isset($changes['is_active'])) {
            return $changes['is_active']['new'] === 1 ? 'relisted' : 'deactivated_filter';
        }

        return 'update';
    }

    /** Encar's "no complectation" in BadgeDetail: the same as none. */
    private const NO_TRIM = '(세부등급 없음)';

    /**
     * One representation per value, whatever PDO or JSON decoding produced. A colour is
     * compared in English: "은회색" is the "Silver" stored before, not a new colour.
     */
    private static function canonical(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($field === 'trim' && trim((string) $value) === self::NO_TRIM) {
            return null;
        }
        if ($field === 'color' && is_string($value)) {
            return self::englishColor($value);
        }
        if ($field === 'options') {
            $list = is_string($value) ? json_decode($value, true) : $value;

            return is_array($list) ? array_values(array_map('strval', $list)) : $value;
        }
        if (is_bool($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }

    private static function englishColor(string $value): string
    {
        static $map = null;
        $map ??= array_change_key_case(TaxonomyCatalog::labelMap('color', 'en'), CASE_LOWER);
        $clean = trim($value);

        return $map[mb_strtolower($clean)] ?? $clean;
    }
}
