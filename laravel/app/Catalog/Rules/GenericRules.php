<?php

namespace App\Catalog\Rules;

/**
 * Engine designations of makes that need no code of their own, described by the make's
 * "engine" in the reference:
 *
 *   "words": {"TDI": "diesel", "Diesel": {"fuel": "diesel", "code": false}}  exact words
 *   "codes": [{"re": "^D\\d{3}$", "fuel": "diesel"}, {"re": "^P\\d{3}e$", "i": true, "fuel": "hybrid"}]
 *   "drives": {"4MOTION": "awd", "AWD": "awd"}
 *
 * The modification is the displacement, the engine words and the drive word, in the
 * badge's order ("2.0 TDI 4MOTION", "B5 AWD", "110 D300"); a word that "code": false only
 * tells the fuel. First matching rule gives the fuel, else the model's; anything else is left over.
 * Nothing is assumed: a badge without a drive word has no drive.
 */
final class GenericRules implements MakeRules
{
    public function __construct(private readonly array $config) {}

    public function modification(string $rest, array $model, array $generation): array
    {
        $fuel = null;
        $drive = null;
        $code = $left = [];
        $text = preg_replace('/\(\s*(\d+)\s*hp\s*\)/i', '$1hp', $rest);
        // Encar's brackets ("3.0 LPi (렌터카용)", "디젤(e-VGT)") hold words like any other
        $text = str_replace(['(', ')'], ' ', $text);
        $drives = $this->config['drives'] ?? [];
        foreach (preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (($key = $this->key($drives, $w)) !== null) {
                $drive ??= $drives[$key]; // "2WD" is a drive word that names no axle: null
                $code[] = $w;
            } elseif (preg_match('/^\d\.\d$/', $w)) {
                $code[] = $w;
            } elseif (($rule = $this->rule($w)) !== null) {
                $fuel ??= $rule['fuel'] ?? null;
                if ($rule['code'] ?? true) {
                    $code[] = $w;
                }
            } else {
                $left[] = $w;
            }
        }

        $fuel ??= $generation['fuel'] ?? $model['fuel'] ?? null;
        $name = $code ? implode(' ', $code) : null;
        // an electric (or fuel-cell) model whose badge names no motor is the model itself
        if ($name === null && $left === [] && in_array($fuel, ['electric', 'hydrogen'], true)) {
            $name = $model['name'];
        }
        return ['code' => $name, 'fuel' => $fuel, 'drive' => $drive, 'leftover' => $left];
    }

    /** @return array{fuel?: ?string, code?: bool}|null */
    private function rule(string $word): ?array
    {
        $words = $this->config['words'] ?? [];
        $key = $this->key($words, $word);
        if ($key !== null) {
            return is_array($words[$key]) ? $words[$key] : ['fuel' => $words[$key]];
        }
        foreach ($this->config['codes'] ?? [] as $rule) {
            if (preg_match('/' . $rule['re'] . '/' . (!empty($rule['i']) ? 'i' : ''), $word)) {
                return $rule;
            }
        }
        return null;
    }

    /** The map's key that is $word, ignoring case. */
    private function key(array $map, string $word): ?string
    {
        foreach (array_keys($map) as $key) {
            if (strcasecmp((string) $key, $word) === 0) {
                return (string) $key;
            }
        }
        return null;
    }
}
