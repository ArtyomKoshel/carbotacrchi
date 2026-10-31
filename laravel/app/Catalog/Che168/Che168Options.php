<?php

namespace App\Catalog\Che168;

/**
 * Names Che168's highlighted options (codes «che168:<optionid>», collector che168 mapping) from
 * resources/catalog/che168/options.json. An id the file does not know keeps Che168's own Chinese
 * name, which the lot carries in raw_data.che168_options: shown as it is, never guessed.
 */
class Che168Options
{
    public const PREFIX = 'che168:';

    /** @var array<string, array{zh: string, ru: string, en: string, category: string, icon: string}>|null */
    private ?array $known = null;

    public function __construct(private readonly ?string $file = null) {}

    /**
     * The lot's option codes as the objects the mini app shows (as OptionEnricher gives Encar's).
     *
     * @param  list<string>  $codes  «che168:101», …
     * @param  array<string, string>  $names  optionid → Che168's Chinese name, from the lot
     * @return list<array{code: string, name_ru: ?string, name_en: ?string, name_zh: ?string, name_kr: null, icon_url: null, icon: ?string, category: ?string}>
     */
    public function describe(array $codes, array $names = []): array
    {
        $out = [];
        foreach ($codes as $code) {
            $id = str_starts_with((string) $code, self::PREFIX) ? substr((string) $code, strlen(self::PREFIX)) : null;
            if ($id === null || $id === '') {
                continue;
            }
            $known = $this->known()[$id] ?? null;
            $zh = $known['zh'] ?? (($names[$id] ?? '') !== '' ? $names[$id] : null);
            $out[] = [
                'code' => (string) $code,
                // an unknown id reads in Chinese rather than not at all
                'name_ru' => $known['ru'] ?? $zh,
                'name_en' => $known['en'] ?? null,
                'name_zh' => $zh,
                'name_kr' => null,
                'icon_url' => null,
                'icon' => $known['icon'] ?? null,
                'category' => $known['category'] ?? null,
            ];
        }

        return $out;
    }

    /** @return array<string, array{zh: string, ru: string, en: string, category: string, icon: string}> */
    private function known(): array
    {
        return $this->known ??= json_decode(
            (string) file_get_contents($this->file ?? resource_path('catalog/che168/options.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['options'];
    }
}
