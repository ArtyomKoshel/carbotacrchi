<?php

namespace App\Catalog\Encar;

use App\Catalog\BadgeGrammar;
use App\Catalog\Reference;
use App\Catalog\Rules\BmwRules;
use App\Catalog\Rules\MakeRules;
use App\Catalog\Rules\MercedesRules;
use App\Catalog\VariantMatch;

/**
 * Places an Encar variant (make_en, model_en, badge, trim) on the reference catalog.
 *
 * Imports carry engine and equipment in the one Badge level ("530i xDrive M 스포츠");
 * BadgeDetail is mostly absent and our old normalization copied Badge into `trim`, so
 * the longer of the two is read when it holds all of the other's words.
 * Korean words become the reference's English ones by resources/catalog/encar/tokens.json;
 * model_en ("5 Series (G60)") gives model and generation, with per-make exceptions in
 * resources/catalog/encar/{make}.json.
 */
class EncarMatcher
{
    private const RULES = ['bmw' => BmwRules::class, 'mercedes' => MercedesRules::class];

    /** @var array<string, string>|null regex → English */
    private ?array $tokens = null;

    /** @var array<string, array> make (lower case) → Encar model map */
    private array $models = [];

    /** @var array<string, MakeRules> */
    private array $rules = [];

    public function __construct(private readonly Reference $reference, private readonly ?string $dir = null) {}

    public function match(string $make, ?string $modelEn, ?string $badge, ?string $trim = null, ?int $year = null, ?string $badgeGroup = null): VariantMatch
    {
        $ref = $this->reference->make($make);
        if ($ref === null) {
            return new VariantMatch(VariantMatch::UNMATCHED, reason: 'make not in reference');
        }
        $makeName = $ref['make'];

        [$modelName, $code] = $this->model($makeName, (string) $modelEn, (string) $badge);
        // some models carry the chassis in BadgeGroup or BadgeDetail ("SL-Class" / "R231")
        foreach ([$badgeGroup, $trim] as $level) {
            if ($code === null && preg_match('/^[A-Z]\d{3}[a-z]?$/', trim((string) $level))) {
                $code = trim($level);
            }
        }
        $model = $this->reference->model($makeName, $modelName);
        $generation = $model ? $this->reference->generation($model, $code, $year) : null;
        if ($generation === null) {
            return new VariantMatch(VariantMatch::UNMATCHED, $makeName, $model['name'] ?? $modelName,
                reason: $model ? "generation {$code} not in reference" : "model {$modelName} not in reference");
        }

        $english = $this->english(self::text($badge, $trim));
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

    /** Badge, or the trim when it holds every word of the badge and more. */
    public static function text(?string $badge, ?string $trim): string
    {
        $badge = trim((string) $badge);
        $trim = trim((string) $trim);
        if ($trim === '' || $trim === '(세부등급 없음)' || $badge === '') {
            return $badge !== '' ? $badge : $trim;
        }
        $words = fn (string $s) => preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
        return count($words($trim)) > count($words($badge)) && !array_diff($words($badge), $words($trim)) ? $trim : $badge;
    }

    /**
     * Reference model name and chassis code: the make's map first ("models", then "badges":
     * a model_en whose badge starts with a word that names another model), then
     * "5 Series (G60)" and "E-Class W213".
     *
     * @return array{0: string, 1: ?string}
     */
    private function model(string $make, string $modelEn, string $badge): array
    {
        $key = mb_strtolower($make);
        $this->models[$key] ??= is_file($this->path("{$key}.json")) ? $this->json("{$key}.json") : [];
        foreach ($this->models[$key]['badges'] ?? [] as $rule) {
            if ($rule['model_en'] === $modelEn && preg_match('/^' . $rule['badge'] . '/u', $badge)) {
                return $rule['to'];
            }
        }
        if (isset($this->models[$key]['models'][$modelEn])) {
            return $this->models[$key]['models'][$modelEn];
        }
        if (preg_match('/^(.*?)\s*\(([A-Za-z0-9]+)\)$/', trim($modelEn), $m)
            || preg_match('/^(.*\S)\s+([A-Z]\d{3}[a-z]?)$/', trim($modelEn), $m)) {
            return [$m[1], $m[2]];
        }
        return [trim($modelEn), null];
    }

    private function rules(array $make): MakeRules
    {
        $name = $make['rules'] ?? '';
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
