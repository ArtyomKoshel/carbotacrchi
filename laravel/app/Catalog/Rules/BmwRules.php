<?php

namespace App\Catalog\Rules;

/**
 * BMW engine designations, written the way BMW writes them per model family:
 *
 *   series (3 Series, 5 Series GT…)  "530i xDrive", "M340i", "740Li xDrive", "530d xDrive" (GT "30d")
 *   X / Z                            "xDrive40i", "sDrive20i", "M50i" (M Performance, always xDrive)
 *   electric (i4, i5, iX…)           "eDrive40", "xDrive50", "M60"
 *   M cars                           "M3 Competition xDrive", "M4 CSL"
 *   M SUVs, XM                       "X5 M Competition", "XM Label"
 *
 * Fuel follows the suffix (i petrol, d diesel, e plug-in hybrid; none — petrol in the 2024+
 * naming), drive follows xDrive (all wheels), else the generation's own drive.
 */
final class BmwRules implements MakeRules
{
    private const SUFFIX_FUEL = ['i' => 'gasoline', 'is' => 'gasoline', 'li' => 'gasoline', 'd' => 'diesel', 'ld' => 'diesel', 'e' => 'hybrid', 'le' => 'hybrid', '' => 'gasoline'];

    private const M_MODIFIERS = ['Competition', 'CS', 'CSL', 'GTS'];

    public function modification(string $rest, array $model, array $generation): array
    {
        $name = $model['name'];
        $fuel = $generation['fuel'] ?? $model['fuel'] ?? null;
        $defaultDrive = $generation['drive'] ?? null;

        // marketing words that name no engine of their own
        $rest = trim(preg_replace('/(^|\s)(iPerformance|V12)(?=\s|$)/', ' ', $rest));
        if (preg_match('/^(3|5|6) Series GT$/', $name)) {
            $rest = trim(preg_replace('/^GT(\s|$)/', '', $rest));
        }

        $driveLetter = null;
        $rest = trim(preg_replace_callback('/(?:(?<=^|\s)M\s+)?(?<=^|\s|M)([xse])Drive(?=\d|\s|$)/', function ($m) use (&$driveLetter) {
            $driveLetter = $m[1];
            return ' ';
        }, $rest));
        $rest = trim(preg_replace('/\s+/', ' ', $rest));
        $drive = match ($driveLetter) {
            'x' => 'awd',
            's', 'e' => $defaultDrive === 'awd' ? 'rwd' : $defaultDrive,
            default => $defaultDrive,
        };
        $driveWord = $driveLetter === null ? '' : "{$driveLetter}Drive";

        // M cars: M2…M8
        if (preg_match('/^M\d$/', $name)) {
            $words = $rest === '' ? [] : explode(' ', $rest);
            if (($words[0] ?? null) === $name) {
                array_shift($words);
            }
            $modifiers = array_values(array_intersect($words, self::M_MODIFIERS));
            $unknown = array_values(array_diff($words, self::M_MODIFIERS));
            $code = trim(implode(' ', [$name, ...$modifiers, $driveLetter === 'x' ? 'xDrive' : '']));
            return self::result($code, $fuel ?? 'gasoline', $drive, $unknown);
        }

        // M SUVs and XM: "3.0 Competition", "4.4 Label" — the displacement adds nothing
        if (preg_match('/^(X\d M|XM)$/', $name)) {
            $words = array_values(array_filter(explode(' ', $rest), fn ($w) => $w !== '' && !preg_match('/^\d\.\d$/', $w)));
            $known = array_values(array_intersect($words, ['Competition', 'Label']));
            $code = trim($name . ' ' . implode(' ', $known));
            return self::result($code, $fuel ?? 'gasoline', $drive, array_values(array_diff($words, $known)));
        }

        // electric: eDrive40, xDrive50, M60; i3 / iX3 name no engine
        if ($fuel === 'electric') {
            if ($rest === '') {
                return self::result($driveWord === '' ? $name : null, $fuel, $drive, []);
            }
            if (!preg_match('/^(M?)(\d{2})$/', str_replace(' ', '', $rest), $m)) {
                return self::result(null, $fuel, $drive, [$rest]);
            }
            return $m[1] === 'M'
                ? self::result("M{$m[2]}", $fuel, 'awd', [])
                : self::result(($driveWord ?: 'eDrive') . $m[2], $fuel, $drive, []);
        }
        if ($name === 'i8') {
            return self::result($rest === '' ? 'i8' : null, $fuel, $drive, $rest === '' ? [] : [$rest]);
        }

        if ($rest === '') {
            return self::result(null, $fuel, $drive, []);
        }
        if (!preg_match('/^(M?)(\d{2,3})(Li|Ld|Le|iS|i|d|e)?$/', str_replace(' ', '', $rest), $m)) {
            return self::result(null, $fuel, $drive, [$rest]);
        }
        [, $mPerf, $digits, $suffix] = $m + [3 => ''];
        $fuel = self::SUFFIX_FUEL[strtolower($suffix)] ?? $fuel;

        // X1…X7, Z4: xDrive20d / sDrive20i; M Performance M40i is all-wheel drive by itself
        if (preg_match('/^[XZ]\d$/', $name)) {
            if ($mPerf === 'M') {
                return self::result("M{$digits}{$suffix}", $fuel, $name === 'Z4' ? 'rwd' : 'awd', []);
            }
            $word = $driveWord !== '' ? $driveWord : ($defaultDrive === 'awd' ? 'xDrive' : 'sDrive');
            return self::result("{$word}{$digits}{$suffix}", $fuel, $drive, []);
        }

        // numbered series; a GT badge may give only the last two digits ("GT 30d xDrive")
        if (preg_match('/^(\d) Series/', $name, $series)) {
            if (strlen($digits) === 2) {
                $digits = $series[1] . $digits;
            }
            if ($digits[0] !== $series[1]) {
                return self::result(null, $fuel, $drive, [$rest]);
            }
        }
        $code = "{$mPerf}{$digits}{$suffix}" . ($driveWord !== '' && $driveLetter !== 'e' ? " {$driveWord}" : '');
        return self::result($code, $fuel, $drive, []);
    }

    private static function result(?string $code, ?string $fuel, ?string $drive, array $leftover): array
    {
        return ['code' => $code, 'fuel' => $fuel, 'drive' => $drive, 'leftover' => $leftover];
    }
}
