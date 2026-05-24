<?php

namespace App\Support\Taxonomy;

final class TaxonomyNormalizer
{
    public static function normalize(string $field, ?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $raw = trim($value);
        if ($raw === '') {
            return '';
        }

        $map = TaxonomyCatalog::normalizationMap($field);
        if ($map === []) {
            return $raw;
        }

        if (isset($map[$raw])) {
            return $map[$raw];
        }

        $lower = mb_strtolower($raw, 'UTF-8');
        if (isset($map[$lower])) {
            return $map[$lower];
        }

        foreach ($map as $k => $v) {
            if (mb_strtolower((string) $k, 'UTF-8') === $lower) {
                return $v;
            }
        }

        return $raw;
    }

    /** @param array<int, string> $values */
    public static function normalizeMany(string $field, array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $normalized = self::normalize($field, (string) $value);
            if ($normalized !== '') {
                $out[$normalized] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Expand canonical/selected value to all DB variants for mixed legacy storage.
     *
     * @return string[]
     */
    public static function expandToDbValues(string $field, ?string $selected): array
    {
        $selected = self::normalize($field, $selected);
        if ($selected === '') {
            return [];
        }

        $variants = [$selected => true];
        $map = TaxonomyCatalog::normalizationMap($field);
        foreach ($map as $raw => $canonical) {
            if ((string) $canonical === $selected && trim((string) $raw) !== '') {
                $variants[(string) $raw] = true;
            }
        }

        return array_keys($variants);
    }

    /** @param array<int, string> $selected */
    public static function expandManyToDbValues(string $field, array $selected): array
    {
        $all = [];
        foreach ($selected as $value) {
            foreach (self::expandToDbValues($field, (string) $value) as $variant) {
                $all[$variant] = true;
            }
        }

        return array_keys($all);
    }
}
