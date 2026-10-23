<?php

namespace App\Catalog\Rules;

/**
 * Mercedes-Benz designations, one spelling for the old and new naming:
 *
 *   "E220d 4MATIC", "S580L 4MATIC", "C220 CDI", "E220 BlueTEC"
 *   "AMG C43 4MATIC"  (also from "C43 AMG 4MATIC"), "AMG GLE63 S 4MATIC+", "AMG S63 E Performance 4MATIC"
 *   "Maybach S680 4MATIC", "EQE350+", "AMG GT 43 4MATIC+", "AMG GT C", "Sprinter 3.0 Diesel"
 *
 * The class letters must be the model's own ("E" in E-Class); a bare number takes them
 * ("200" of an SLK → "SLK200"). Fuel: d / CDI / BlueTEC diesel, e / Hybrid / E Performance
 * plug-in hybrid, EQ electric; 4MATIC all wheels, "+" on an EQ the rear ones.
 */
final class MercedesRules implements MakeRules
{
    private const CODE = '/^(CLA|CLE|CLS|GLA|GLB|GLC|GLE|GLK|GLS|GL|ML|SLC|SLK|SL|EQA|EQB|EQC|EQE|EQS|GT|A|B|C|E|G|S|V)?(\d{2,3})(eL|Ld|L|d|e|h)?(\+)?$/';

    public function modification(string $rest, array $model, array $generation): array
    {
        $class = $model['class'] ?? null;
        $fuel = $generation['fuel'] ?? $model['fuel'] ?? null;
        $drive = $generation['drive'] ?? null;

        $text = ' ' . str_replace('4MAITC', '4MATIC', $rest) . ' ';
        $flag = function (string $pattern) use (&$text): ?string {
            if (!preg_match($pattern, $text, $m)) {
                return null;
            }
            $text = str_replace($m[0], ' ', $text);
            return $m[1] ?? $m[0];
        };

        $matic = $flag('/ (4MATIC\+?) /');
        $maybach = $flag('/ (Maybach) /');
        $amg = $flag('/ (AMG) /');
        $ePerf = $flag('/ (E-Performance) /');
        $eq = $flag('/ (EQ(?: Technology)?) /');
        $diesel = $flag('/ (CDI|BlueTEC) /');
        $hybrid = $flag('/ ((?:Plug-in )?Hybrid) /');
        $flag('/ (BlueEFFICIENCY) /');
        $fuelWord = $flag('/ (Diesel|Gasoline) /');
        // "S350 d", "S350L d", "B200 d", "CLA 45" → one word
        $text = preg_replace('/(\d{2,3}L?) (d|e|L)(?= )/', '$1$2', $text);
        if ($class !== null) {
            $text = preg_replace('/ (' . preg_quote($class, '/') . ') (\d{2,3})(?=[ a-zA-Z+])/', ' $1$2', $text);
        }

        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $s = in_array('S', $words, true) && ($amg || $class === 'GT') ? 'S' : null;
        $words = array_values(array_filter($words, fn ($w) => !($s && $w === 'S')));

        if ($class === null) {
            // Sprinter: only the displacement
            $disp = array_values(array_filter($words, fn ($w) => preg_match('/^\d\.\d$/', $w)));
            $left = array_values(array_diff($words, $disp));
            $code = $disp ? trim("{$disp[0]} {$fuelWord}") : null;
            return self::result($code, $fuelWord ? strtolower($fuelWord) : $fuel, $drive, $left);
        }

        if ($class === 'GT') {
            return $this->amgGt($words, $s, $ePerf, $matic, $fuel, $drive);
        }

        $codes = [];
        $left = [];
        foreach ($words as $w) {
            if (preg_match(self::CODE, $w, $m) && ($m[1] === '' || $m[1] === $class)) {
                $codes[] = $m;
            } elseif (preg_match('/^\d\.\d$/', $w)) {
                continue; // a displacement next to a code adds nothing
            } else {
                $left[] = $w;
            }
        }
        if (count($codes) !== 1) {
            return self::result(null, $fuel, $matic ? 'awd' : $drive, $codes ? array_merge(array_map(fn ($m) => $m[0], $codes), $left) : $left);
        }
        [, , $digits, $suffix, $plus] = $codes[0] + [3 => '', 4 => ''];

        $fuel = match (true) {
            str_starts_with($class, 'EQ') || $eq !== null => 'electric',
            in_array($suffix, ['e', 'eL', 'h'], true) || $hybrid !== null || $ePerf !== null => 'hybrid',
            in_array($suffix, ['d', 'Ld'], true) || $diesel !== null || $fuelWord === 'Diesel' => 'diesel',
            default => $fuel ?? 'gasoline',
        };
        $drive = match (true) {
            $matic !== null => 'awd',
            $plus === '+' => 'rwd',
            default => $drive,
        };

        $code = implode(' ', array_filter([
            $maybach, $amg ? 'AMG' : null, "{$class}{$digits}{$suffix}{$plus}", $s,
            $ePerf ? 'E Performance' : null, $diesel, $hybrid, $eq ? 'EQ Technology' : null, $matic,
        ]));
        return self::result($code, $fuel, $drive, $left);
    }

    /** AMG GT: "4.0 C Roadster" → "AMG GT C", "43 4MATIC+" → "AMG GT 43 4MATIC+", "GT63 S E Performance". */
    private function amgGt(array $words, ?string $s, ?string $ePerf, ?string $matic, ?string $fuel, ?string $drive): array
    {
        $number = null;
        $letters = [];
        $left = [];
        foreach ($words as $w) {
            if (preg_match('/^(?:GT)?(\d{2})$/', $w, $m)) {
                $number = $m[1];
            } elseif (in_array($w, ['C', 'R'], true)) {
                $letters[] = $w;
            } elseif ($w !== 'GT' && !preg_match('/^\d\.\d$/', $w)) {
                $left[] = $w;
            }
        }
        $code = implode(' ', array_filter(['AMG GT', $number, $s, ...$letters, $ePerf ? 'E Performance' : null, $matic]));
        return self::result($code, $ePerf ? 'hybrid' : ($fuel ?? 'gasoline'), $matic ? 'awd' : $drive, $left);
    }

    private static function result(?string $code, ?string $fuel, ?string $drive, array $leftover): array
    {
        return ['code' => $code, 'fuel' => $fuel, 'drive' => $drive, 'leftover' => $leftover];
    }
}
