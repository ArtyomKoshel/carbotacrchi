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
