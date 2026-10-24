<?php

namespace App\Catalog;

/**
 * The make-independent part of reading an English badge: seats, body, special editions and
 * complectations are cut out by the reference's own names (longest first), and what is left
 * is the engine designation for the make's rules (App\Catalog\Rules\*).
 *
 *   "xDrive 40i M Sport Pro Special Edition 6-seater"
 *   → seats 6, editions [Special Edition], complectation "M Sport Pro", rest "xDrive 40i"
 */
final class BadgeGrammar
{
    /** body word → body type, unless the model overrides it ("bodies" in the reference) */
    public const BODIES = [
        'Gran Coupe' => 'liftback', 'Gran Turismo' => 'liftback', 'Touring' => 'wagon', 'Estate' => 'wagon',
        'Shooting Brake' => 'wagon', '4-door' => 'liftback', 'Coupe' => 'coupe', 'Avant' => 'wagon',
        'Sportback' => 'liftback', 'Cross Turismo' => 'wagon', 'Sport Turismo' => 'wagon',
        'Convertible' => 'convertible', 'Cabriolet' => 'convertible', 'Roadster' => 'roadster', 'Spyder' => 'convertible',
        'Sedan' => 'sedan', 'Hatchback' => 'hatchback', '5-door' => 'hatchback', '3-door' => 'hatchback',
        'Van' => 'van', 'Cargo' => 'van', 'Wagon' => 'wagon', 'Saloon' => 'sedan',
    ];

    /**
     * @return array{rest: string, body: ?string, seats: ?int, editions: list<string>, complectation: ?string}
     */
    public static function read(string $badge, array $make, ?array $model): array
    {
        $text = ' ' . preg_replace('/\s+/u', ' ', trim($badge)) . ' ';

        $seats = null;
        if (preg_match('/ (\d{1,2})-seater /', $text, $m)) {
            $seats = (int) $m[1];
            $text = str_replace($m[0], ' ', $text);
        }

        // a model maps a body word to "" where the word is a modification of its own (911 GT3 Touring)
        $bodyWords = array_keys(array_filter(($model['bodies'] ?? []) + self::BODIES, fn ($type) => $type !== ''));
        $bodyWord = self::cut($text, $bodyWords)[0] ?? null;
        $body = $bodyWord === null ? null : ($model['bodies'][$bodyWord] ?? self::BODIES[$bodyWord]);

        $editions = self::cut($text, $make['editions'] ?? []);
        $complectations = self::cut($text, $make['complectations'] ?? []);

        return [
            'rest' => trim(preg_replace('/\s+/', ' ', $text)),
            'body' => $body,
            'seats' => $seats,
            'editions' => $editions,
            'complectation' => $complectations ? implode(' ', $complectations) : null,
        ];
    }

    /**
     * Cut every listed phrase out of $text, longest first; the phrases found, in the order
     * they stood in the text.
     *
     * @param  list<string> $phrases
     * @return list<string>
     */
    private static function cut(string &$text, array $phrases): array
    {
        usort($phrases, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        $found = [];
        foreach ($phrases as $phrase) {
            $pattern = '/(?<=\s)' . str_replace('\ ', '\s+', preg_quote($phrase, '/')) . '(?=\s)/iu';
            // every occurrence goes (badge and BadgeDetail may both say "스타일"), the phrase counts once
            if (preg_match_all($pattern, $text, $all, PREG_OFFSET_CAPTURE)) {
                $found[$all[0][0][1]] = $phrase;
                foreach ($all[0] as [$hit, $at]) {
                    $text = substr_replace($text, str_repeat(' ', strlen($hit)), $at, strlen($hit));
                }
            }
        }
        ksort($found);
        return array_values($found);
    }
}
