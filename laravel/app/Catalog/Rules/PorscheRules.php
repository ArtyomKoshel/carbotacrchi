<?php

namespace App\Catalog\Rules;

/**
 * Porsche designations, the model left out: "Carrera 4 GTS", "Turbo S", "GT3 RS", "Targa 4S",
 * "4 E-Hybrid", "S Diesel", "Turbo GT", "Base". Encar's "AWD" of a Panamera is Porsche's "4";
 * the displacement names a 718 ("GTS 4.0" vs "GTS 2.5") and a badge that has nothing else ("3.0").
 *
 * Only what the badge says: all-wheel drive from a "4", fuel from E-Hybrid / Diesel or the model.
 */
final class PorscheRules implements MakeRules
{
    private const WORDS = ['Carrera', 'Targa', '4', '4S', 'S', 'GTS', 'Turbo', 'GT', 'GT2', 'GT3', 'GT4', 'RS', 'Touring',
        'Spyder', 'E-Hybrid', 'Diesel', 'Executive'];

    public function modification(string $rest, array $model, array $generation): array
    {
        $name = $model['name'];
        $fuel = $generation['fuel'] ?? $model['fuel'] ?? null;

        $disp = null;
        $base = false;
        $words = $left = [];
        foreach (preg_split('/\s+/', trim($rest), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (preg_match('/^\d\.\d$/', $w)) {
                $disp = $w;
            } elseif ($w === 'AWD') {
                $words[] = '4';
            } elseif ($w === '2WD') {
                continue;
            } elseif ($w === 'Base') {
                $base = true;
            } elseif (in_array($w, self::WORDS, true)) {
                $words[] = $w;
            } else {
                $left[] = $w;
            }
        }

        $designation = implode(' ', $words);
        $code = match (true) {
            $left !== [] => null,
            (str_starts_with($name, '718') || $designation === '') && $disp !== null => trim("{$designation} {$disp}"),
            $designation !== '' => $designation,
            $base => 'Base',
            default => null,
        };

        $awd = (bool) array_intersect($words, ['4', '4S']);
        $fuel = match (true) {
            in_array('E-Hybrid', $words, true) => 'hybrid',
            in_array('Diesel', $words, true) => 'diesel',
            default => $fuel,
        };

        return ['code' => $code, 'fuel' => $fuel, 'drive' => $awd ? 'awd' : null, 'leftover' => $left];
    }
}
