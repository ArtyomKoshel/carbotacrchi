<?php

namespace App\Support;

/**
 * A lot's photo gallery, stored compactly on the `lots` row.
 *
 * Encar photo URLs all follow one template, with `dir` and `base` constant
 * within a lot (`base` is NOT always the lot id — relists keep the old one):
 *   https://ci.encar.com/carpicture{dir:02}/pic{base[:4]}/{base}_{code}.{jpg|JPG}?impolicy=…
 * so a lot stores photo_dir, photo_base_id, photo_codes ("021,001,009") and
 * photo_ext_upper. Anything else (KBChacha, a changed CDN) is kept verbatim in
 * photo_urls (JSON) with photo_codes = ''.
 *
 * Python mirror (writer): parser/lot_compact.py — keep both in sync.
 */
class LotPhotos
{
    public const QUERY = 'impolicy=heightRate&rh=480&cw=640&ch=480&cg=Center';

    private const PATTERN = '~^https://ci\.encar\.com/carpicture(\d{2})/pic(\d{4})/([1-9]\d{3,9})_(\d{3})\.(jpg|JPG)\?'
        . 'impolicy=heightRate&rh=480&cw=640&ch=480&cg=Center$~';

    private const INT_UNSIGNED_MAX = 4294967295;

    public const COLUMNS = ['photo_dir', 'photo_base_id', 'photo_codes', 'photo_ext_upper', 'photo_urls'];

    /**
     * Same as urls() but without the impolicy query — full-resolution, no CDN overlay.
     * Used when sending to Telegram (CDN impolicy may add the encar.com watermark badge).
     *
     * @return list<string>
     */
    public static function rawUrls(array|object $lot): array
    {
        return array_map(
            fn (string $url) => (string) strtok($url, '?'),
            self::urls($lot),
        );
    }

    /**
     * Ordered photo URLs of a lots row (array or stdClass).
     *
     * @return list<string>
     */
    public static function urls(array|object $lot): array
    {
        $lot = (array) $lot;

        if (!empty($lot['photo_urls'])) {
            $urls = is_string($lot['photo_urls']) ? json_decode($lot['photo_urls'], true) : $lot['photo_urls'];
            return is_array($urls) ? array_values($urls) : [];
        }
        if (empty($lot['photo_codes'])) {
            return [];
        }

        $base = (string) $lot['photo_base_id'];
        $ext  = !empty($lot['photo_ext_upper']) ? 'JPG' : 'jpg';
        $head = sprintf('https://ci.encar.com/carpicture%02d/pic%s/%s_', (int) $lot['photo_dir'], substr($base, 0, 4), $base);

        return array_map(
            fn (string $code) => "{$head}{$code}.{$ext}?" . self::QUERY,
            explode(',', $lot['photo_codes']),
        );
    }

    /**
     * Ordered photo URLs → lots.photo_* column values (all null when no photos).
     *
     * @param  list<string>|null $urls
     * @return array<string, mixed>
     */
    public static function encode(?array $urls): array
    {
        $empty = array_fill_keys(self::COLUMNS, null);
        $empty['photo_ext_upper'] = 0;
        if (!$urls) {
            return $empty;
        }

        $dirs = $bases = $exts = $codes = [];
        foreach ($urls as $url) {
            if (!is_string($url) || !preg_match(self::PATTERN, $url, $m) || $m[2] !== substr($m[3], 0, 4)) {
                return self::verbatim($empty, $urls);
            }
            $dirs[(int) $m[1]] = true;
            $bases[$m[3]]      = true;
            $exts[$m[5]]       = true;
            $codes[]           = $m[4];
        }

        $dir  = array_key_first($dirs);
        $base = (string) array_key_first($bases);
        if (count($dirs) > 1 || count($bases) > 1 || count($exts) > 1
            || $dir < 1 || $dir > 99 || (int) $base > self::INT_UNSIGNED_MAX) {
            return self::verbatim($empty, $urls);
        }

        return [
            'photo_dir'       => $dir,
            'photo_base_id'   => (int) $base,
            'photo_codes'     => implode(',', $codes),
            'photo_ext_upper' => isset($exts['JPG']) ? 1 : 0,
            'photo_urls'      => null,
        ];
    }

    private static function verbatim(array $empty, array $urls): array
    {
        return array_merge($empty, [
            'photo_codes' => '',
            'photo_urls'  => json_encode(array_values($urls), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
