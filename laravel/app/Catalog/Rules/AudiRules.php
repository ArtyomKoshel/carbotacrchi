<?php

namespace App\Catalog\Rules;

/**
 * Audi designations: power class or displacement, engine, then the extras, in Audi's order:
 *
 *   "45 TFSI quattro", "40 TDI", "55 TFSI e quattro", "2.0 TDI" (before 2017), "50 TDI quattro L" (A8 LWB)
 *   e-tron: "55 quattro", "40", "S", "Performance"; R8: "V10 Performance"; TTS: "2.0 TFSI quattro Competition"
 *
 * Fuel: TDI diesel, TFSI e plug-in hybrid, TFSI / FSI petrol; quattro is all-wheel drive, else
 * the generation's own. A badge left with nothing (RS e-tron GT) is the model itself.
 */
final class AudiRules implements MakeRules
{
    private const FLAGS = ['V10', 'GT', 'RS', 'S', 'Performance', 'Plus', 'Competition'];

    public function modification(string $rest, array $model, array $generation): array
    {
        $name = $model['name'];
        $fuel = $generation['fuel'] ?? $model['fuel'] ?? null;
        $drive = $generation['drive'] ?? null;
        $words = preg_split('/\s+/', trim(preg_replace('/\bTFSI e\b/', 'TFSIe', $rest)), -1, PREG_SPLIT_NO_EMPTY);

        $power = $engine = null;
        $flags = $left = [];
        $quattro = $long = false;
        foreach ($words as $w) {
            match (true) {
                $w === 'quattro' => $quattro = true,
                $w === 'LWB' => $long = true,
                preg_match('/^(\d{2}|\d\.\d)$/', $w) === 1 && $power === null => $power = $w,
                in_array($w, ['TFSI', 'TFSIe', 'TDI', 'FSI'], true) && $engine === null => $engine = $w,
                in_array($w, self::FLAGS, true) => $flags[] = $w,
                default => $left[] = $w,
            };
        }
        // the model's own name in the badge ("GT RS" of an RS e-tron GT) adds nothing
        $flags = array_values(array_filter($flags, fn ($f) => !preg_match('/(^|\s)' . preg_quote($f, '/') . '(\s|$)/', $name)));

        $fuel = match ($engine) {
            'TDI' => 'diesel',
            'TFSIe' => 'hybrid',
            'TFSI', 'FSI' => 'gasoline',
            default => $fuel ?? ($power !== null || $flags ? 'gasoline' : null),
        };
        $parts = array_filter([$power, $engine === 'TFSIe' ? 'TFSI e' : $engine, ...$flags, $quattro ? 'quattro' : null, $long ? 'L' : null]);
        $code = $parts ? implode(' ', $parts) : ($left ? null : $name);

        return ['code' => $code, 'fuel' => $fuel, 'drive' => $quattro ? 'awd' : $drive, 'leftover' => $left];
    }
}
