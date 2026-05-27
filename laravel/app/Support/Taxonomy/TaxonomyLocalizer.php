<?php

namespace App\Support\Taxonomy;

final class TaxonomyLocalizer
{
    public static function label(string $field, ?string $value, string $locale = 'ru'): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $map = TaxonomyCatalog::labelMap($field, $locale);
        $lower = mb_strtolower($value, 'UTF-8');

        return $map[$lower] ?? $value;
    }

    /**
     * Localize Korean trim values using the translations DB table.
     * Returns [{value: kr, label: en|ru}] sorted by label.
     *
     * @param  array<int, string> $krValues
     * @return array<int, array{value: string, label: string}>
     */
    public static function trimOptions(array $krValues, string $locale = 'en'): array
    {
        if (empty($krValues)) {
            return [];
        }

        $byKr = [];
        try {
            $rows = \App\Models\Translation::where('category', 'trim')
                ->whereIn('kr', $krValues)
                ->get(['kr', 'en', 'ru']);

            foreach ($rows as $row) {
                $label = ($locale === 'ru')
                    ? ($row->ru ?? $row->en ?? null)
                    : ($row->en ?? null);
                if ($label !== null && $label !== '') {
                    $byKr[$row->kr] = $label;
                }
            }
        } catch (\Throwable) {
        }

        $result = [];
        $seen   = [];
        foreach ($krValues as $kr) {
            $kr = trim((string) $kr);
            if ($kr === '' || isset($seen[$kr])) {
                continue;
            }
            $seen[$kr] = true;
            $result[]  = ['value' => $kr, 'label' => $byKr[$kr] ?? $kr];
        }

        usort($result, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $result;
    }

    /**
     * @param array<int, string> $values
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(string $field, array $values, string $locale = 'ru'): array
    {
        $result = [];
        $seen = [];

        foreach ($values as $value) {
            $canonical = TaxonomyNormalizer::normalize($field, (string) $value);
            if ($canonical === '') {
                continue;
            }
            if (isset($seen[$canonical])) {
                continue;
            }
            $seen[$canonical] = true;

            $result[] = [
                'value' => $canonical,
                'label' => self::label($field, $canonical, $locale),
            ];
        }

        usort($result, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $result;
    }
}
