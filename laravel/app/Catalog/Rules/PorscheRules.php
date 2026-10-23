<?php

namespace App\Catalog\Rules;

/**
 * Porsche designations, the model left out: "Carrera 4 GTS", "Turbo S", "GT3 RS", "Targa 4S",
 * "4 E-Hybrid", "S Diesel", "Turbo GT", "Base". Encar's "AWD" of a Panamera is Porsche's "4";
 * the displacement only names a 718 ("GTS 4.0" vs "GTS 2.5") or, alone, the base car.
 *
 * Drive: a "4" is all wheels; so are Turbo on a 911 and GTS / Turbo on the others, else the
 * generation's own. Fuel: E-Hybrid plug-in hybrid, Diesel diesel.
 */
final class PorscheRules implements MakeRules
{
    private const WORDS = ['Carrera', 'Targa', '4', '4S', 'S', 'GTS', 'Turbo', 'GT', 'GT2', 'GT3', 'GT4', 'RS', 'Touring',
        'Spyder', 'E-Hybrid', 'Diesel', 'Executive'];

    public function modification(string $rest, array $model, array $generation): array
    {
        $name = $model['name'];
        $fuel = $generation['fuel'] ?? $model['fuel'] ?? null;
        $sports = preg_match('/^(911|718 |Boxster|Cayman)/', $name) === 1;

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
            str_starts_with($name, '718') && $disp !== null => trim("{$designation} {$disp}"),
            $designation !== '' => $designation,
            $base || $disp !== null => 'Base',
            default => null,
        };

        $awd = array_intersect($words, ['4', '4S']) || in_array('Turbo', $words, true)
            || (!$sports && in_array('GTS', $words, true));
        $fuel = match (true) {
            in_array('E-Hybrid', $words, true) => 'hybrid',
            in_array('Diesel', $words, true) => 'diesel',
            default => $fuel ?? 'gasoline',
        };

        return ['code' => $code, 'fuel' => $fuel, 'drive' => $awd ? 'awd' : ($generation['drive'] ?? null), 'leftover' => $left];
    }
}
