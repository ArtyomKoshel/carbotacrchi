<?php

namespace App\Catalog\Encar;

use App\Catalog\BadgeGrammar;
use App\Catalog\Reference;
use App\Catalog\Rules\AudiRules;
use App\Catalog\Rules\BmwRules;
use App\Catalog\Rules\GenericRules;
use App\Catalog\Rules\MakeRules;
use App\Catalog\Rules\MercedesRules;
use App\Catalog\Rules\PorscheRules;
use App\Catalog\VariantMatch;

/**
 * Places an Encar variant (make, model, badge, trim as Encar names them) on the reference catalog.
 *
 * Only Encar's own Korean fields are read, never our translations: those come hours late
 * (normalization waits for a run to end) and go stale when a listing changes model.
 * The Korean model holds the chassis ("5시리즈 (G30)", "E-클래스 W213"); its name becomes
 * Encar's English one by the make's "names" (resources/catalog/encar/{make}.json, taken from
 * lots that carry both), with per-make exceptions there too.
 *
 * Imports carry engine and equipment in the one Badge level ("530i xDrive M 스포츠");
 * BadgeDetail is mostly absent and our old normalization copied Badge into `trim`, so
 * the longer of the two is read when it holds all of the other's words.
 * Korean words become the reference's English ones by resources/catalog/encar/tokens.json.
 */
class EncarMatcher
{
    private const RULES = [
        'bmw' => BmwRules::class, 'mercedes' => MercedesRules::class, 'audi' => AudiRules::class, 'porsche' => PorscheRules::class,
    ];

    /** @var array<string, string>|null regex → English */
    private ?array $tokens = null;

    /** @var array<string, array>|null Encar's Korean make → that make's file, plus 'make' => reference name */
    private ?array $makes = null;

    /** @var array<string, MakeRules> */
    private array $rules = [];

    public function __construct(private readonly Reference $reference, private readonly ?string $dir = null) {}

    /**
     * @param array{make: ?string, model: ?string, badge_group?: ?string, badge?: ?string, trim?: ?string,
     *              make_en?: ?string, model_en?: ?string} $lot a lots row or a variant of them;
     *        make_en / model_en only stand in when the Korean field is empty
     */
    public function match(array $lot): VariantMatch
    {
        $encar = $this->encarMake((string) ($lot['make'] ?? ''), (string) ($lot['make_en'] ?? ''));
        $ref = $encar ? $this->reference->make($encar['make']) : null;
        if ($ref === null) {
            return new VariantMatch(VariantMatch::UNMATCHED, reason: 'make not in reference');
        }
        $makeName = $ref['make'];
        $badge = $lot['badge'] ?? null;
        $trim = $lot['trim'] ?? null;

        [$modelName, $code, $modelWords] = $this->model($encar, trim((string) ($lot['model'] ?? '')) ?: trim((string) ($lot['model_en'] ?? '')), (string) $badge);
        // a make may name the engine before the model ("Cooper S Countryman"): it goes to the
        // badge, and what is left names the model through "engine_models" ("" → "Hatch")
        $engine = '';
        if (isset($encar['engine_prefix']) && preg_match('/^(' . $encar['engine_prefix'] . ')(?:\s+(.*))?$/u', $modelName, $m)) {
            $engine = $m[1];
            $modelName = $encar['engine_models'][$m[2] ?? ''] ?? ($m[2] ?? '');
        }
        $model = $this->reference->model($makeName, $modelName);
        // some models carry the chassis in BadgeGroup or BadgeDetail ("A6" / "C7", "SL-Class" / "R231", "Cooper" / "3세대")
        foreach (['badge_group' => $lot['badge_group'] ?? null, 'trim' => $trim] as $level => $value) {
            $key = self::generationKey((string) $value) ?? trim((string) $value);
            if ($model && $code === null && $key !== '' && $this->reference->generation($model, $key)) {
                $code = $key;
                if ($level === 'trim') {
                    $trim = null; // the BadgeDetail was the generation, not a complectation
                }
            }
        }
        $generation = $model ? $this->reference->generation($model, $code) : null;
        // a BadgeDetail that names a generation of the model ("A6" + "C7") is never a complectation
        if ($model && $trim !== null && $this->reference->generation($model, self::generationKey($trim) ?? trim($trim))) {
            $trim = null;
        }
        if ($generation === null) {
            return new VariantMatch(VariantMatch::UNMATCHED, $makeName, $model['name'] ?? $modelName, reason: match (true) {
                $model === null => "model {$modelName} not in reference",
                $code === null => 'Encar names no generation',
                default => "generation {$code} not in reference",
            });
        }

        $english = trim("{$engine} {$modelWords} " . $this->english(self::text($badge, $trim)));
        // the make's own spellings of the reference's words ("AB" → "Autobiography")
        foreach ($encar['words'] ?? [] as $from => $to) {
            $english = preg_replace('/(?<=^|\s)' . preg_quote($from, '/') . '(?=\s|$)/u', $to, $english);
        }
        $read = BadgeGrammar::read($english, $ref, $model);
        $mod = $this->rules($ref)->modification($read['rest'], $model, $generation);

        $status = match (true) {
            $mod['leftover'] !== [] => VariantMatch::UNMATCHED,
            $mod['code'] === null => VariantMatch::NO_ENGINE,
            default => VariantMatch::MATCHED,
        };

        return new VariantMatch(
            status: $status,
            make: $makeName,
            model: $model['name'],
            generation: $generation['code'],
            body: $read['body'] ?? $model['body'] ?? null,
            modification: $mod['code'],
            fuel: $mod['fuel'],
            drive: $mod['drive'],
            complectation: $read['complectation'],
            editions: $read['editions'],
            seats: $read['seats'],
            leftover: $mod['leftover'],
            reason: $status === VariantMatch::UNMATCHED ? 'unknown words' : null,
        );
    }

    /**
     * Korean words → English by the token list; unknown ones stay as they are. Longest
     * first; words of three syllables and more are found inside run-together text
     * ("퍼스트에디션"), shorter ones only standing alone, so 하이 is not read inside 하이브리드.
     */
    public function english(string $text): string
    {
        if ($this->tokens === null) {
            $list = $this->json('tokens.json')['tokens'];
            uksort($list, fn (string $a, string $b) => mb_strlen(str_replace(' ', '', $b)) <=> mb_strlen(str_replace(' ', '', $a)));
            $this->tokens = [];
            foreach ($list as $kr => $en) {
                // spaces are optional between two Korean letters and where the key has one;
                // "i퍼포먼스" is not "M340i 퍼포먼스", "E 퍼포먼스" is
                $chars = preg_split('//u', $kr, -1, PREG_SPLIT_NO_EMPTY);
                $body = '';
                foreach ($chars as $i => $c) {
                    if ($c === ' ') {
                        continue;
                    }
                    $prev = $chars[$i - 1] ?? '';
                    $spaced = $prev === ' ' || preg_match('/^\p{Hangul}{2}$/u', ($chars[$i - 1] ?? '') . $c);
                    $body .= ($body !== '' && $spaced ? '\s*' : '') . preg_quote($c, '/');
                }
                $short = preg_match_all('/\p{Hangul}/u', $kr) <= 2;
                $head = preg_match('/^[A-Za-z0-9]/', $kr) ? '(?<![A-Za-z0-9])' : '';
                $this->tokens['/' . $head . ($short ? "(?<!\\p{Hangul}){$body}(?!\\p{Hangul})" : $body) . '/u'] = $en;
            }
        }
        foreach ($this->tokens as $pattern => $en) {
            $text = preg_replace($pattern, " {$en} ", $text);
        }
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * What to read of Badge and BadgeDetail (`trim`): the trim when it holds every word of the
     * badge and more (an import's full name), the badge when the trim adds nothing (our old
     * normalization's copy, or a stale shorter name), else both — a Korean make's badge is the
     * engine ("2.0 LPI") and its BadgeDetail the complectation ("시그니처").
     */
    public static function text(?string $badge, ?string $trim): string
    {
        $badge = trim((string) $badge);
        $trim = trim((string) $trim);
        if ($trim === '' || $trim === '(세부등급 없음)' || $badge === '') {
            return $badge !== '' ? $badge : $trim;
        }
        $words = fn (string $s) => preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
        $b = $words($badge);
        $t = $words($trim);
        return match (true) {
            count($t) > count($b) && ! array_diff($b, $t) => $trim,
            // sharing some words but not all: our old normalization's copy of an earlier badge
            // ("520i M 스포츠" under "520i 스페셜 에디션"), not a BadgeDetail
            (bool) array_intersect($t, $b) => $badge,
            default => "{$badge} {$trim}",
        };
    }

    /**
     * Reference model name, generation and words for the badge, of Encar's model:
     *   - "encar_models": Encar's model exactly as Encar writes it → model, generation, and the
     *     words its name says of the engine ("쏘나타 디 엣지 하이브리드(DN8)" → Sonata, DN8 FL, "Hybrid"):
     *     Encar keeps a facelift or a powertrain as a model of its own, we keep it as the generation's;
     *   - else "5시리즈 (G30)", "E-클래스 W213" or the English model_en: the name through the make's
     *     "names", then the exceptions "badges" (a model whose badge names another one) and "models".
     *
     * @return array{0: string, 1: ?string, 2: string}
     */
    private function model(array $encar, string $encarModel, string $badge): array
    {
        if (isset($encar['encar_models'][$encarModel])) {
            $m = $encar['encar_models'][$encarModel];
            return [$m['model'], $m['generation'] ?? null, $m['words'] ?? ''];
        }
        [$name, $code] = self::split($encarModel);
        $name = $encar['names'][$name] ?? $name;
        foreach ($encar['badges'] ?? [] as $rule) {
            if (self::split($rule['model_en']) === [$name, $code] && preg_match('/^' . $rule['badge'] . '/u', $badge)) {
                return [$rule['to'][0], $rule['to'][1] ?? null, ''];
            }
        }
        // a key without a chassis code renames every generation of that name; its generation
        // (an unnumbered first one) holds only where Encar named none — a stated code always wins
        foreach ($encar['models'] ?? [] as $key => $to) {
            [$keyName, $keyCode] = self::split($key);
            if ($keyName !== $name) {
                continue;
            }
            if ($keyCode === $code) {
                return [$to[0], $to[1] ?? $code, ''];
            }
            if ($keyCode === null) {
                return [$to[0], $code, ''];
            }
        }
        return [$name, $code, ''];
    }

    /**
     * "5 Series (G60)" / "E-Class W213" / "XC90 2세대" / "Tiguan Gen 2" / "A6" → [name, chassis
     * code, "#2" for a generation number, or null]
     */
    private static function split(string $model): array
    {
        $model = trim($model);
        if (preg_match('/^(.*?)\s*\(([A-Za-z0-9]+)\)$/u', $model, $m) || preg_match('/^(.*\S)\s+([A-Z]\d{3}[a-z]?)$/u', $model, $m)) {
            return [$m[1], self::generationKey($m[2]) ?? $m[2]];
        }
        if (preg_match('/^(.*\S)\s+(\d세대|Gen \d)$/u', $model, $m)) {
            return [$m[1], self::generationKey($m[2])];
        }
        return [$model, null];
    }

    /** "2세대" / "Gen 2" → "#2" */
    private static function generationKey(string $level): ?string
    {
        return preg_match('/^(?:(\d)세대|Gen (\d))$/u', trim($level), $m) ? '#' . ($m[1] ?: $m[2]) : null;
    }

    /** Encar's Korean name of a reference make ("Mercedes-Benz" → "벤츠"). */
    public function koreanMake(string $make): ?string
    {
        return $this->encarMake('', $make)['korean'] ?? null;
    }

    /** The Encar file of a make, by Encar's Korean make or else by our English one. */
    private function encarMake(string $korean, string $english): ?array
    {
        if ($this->makes === null) {
            $this->makes = [];
            foreach (glob($this->path('*.json')) as $file) {
                if (basename($file) === 'tokens.json') {
                    continue;
                }
                $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                $ref = $this->reference->make(basename($file, '.json'));
                if ($ref !== null && isset($data['korean'])) {
                    $this->makes[$data['korean']] = $data + ['make' => $ref['make']];
                }
            }
        }
        if (isset($this->makes[trim($korean)])) {
            return $this->makes[trim($korean)];
        }
        foreach ($this->makes as $data) {
            if (strcasecmp($data['make'], trim($english)) === 0) {
                return $data;
            }
        }
        return null;
    }

    /** The make's own rules, or GenericRules with the make's "engine". */
    private function rules(array $make): MakeRules
    {
        $name = $make['rules'] ?? 'generic';
        if ($name === 'generic') {
            return $this->rules[$make['make']] ??= new GenericRules($make['engine'] ?? []);
        }
        return $this->rules[$name] ??= new (self::RULES[$name] ?? throw new \RuntimeException("no rules '{$name}' for {$make['make']}"));
    }

    private function json(string $file): array
    {
        return json_decode(file_get_contents($this->path($file)), true, flags: JSON_THROW_ON_ERROR);
    }

    private function path(string $file): string
    {
        return ($this->dir ?? resource_path('catalog/encar')) . '/' . $file;
    }
}
