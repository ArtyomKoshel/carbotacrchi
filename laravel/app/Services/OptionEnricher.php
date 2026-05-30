<?php

namespace App\Services;

use App\Models\EncarOptionCatalog;
use Illuminate\Support\Collection;

/**
 * Обогащает коды опций [`"001"`, `"014"`] данными из каталога.
 *
 * Каталог грузится один раз за запрос (кэш Redis 24h).
 * Вызывается в SearchController после сборки ответа.
 */
class OptionEnricher
{
    private ?Collection $catalog = null;

    private function catalog(): Collection
    {
        return $this->catalog ??= EncarOptionCatalog::getCached();
    }

    /**
     * Превратить массив кодов в массив объектов с именами и иконкой.
     *
     * Вход:  ["001", "014", "055"]
     * Выход: [
     *   ["code"=>"001", "name_ru"=>"Люк",       "name_en"=>"Sunroof", "icon_url"=>"https://..."],
     *   ["code"=>"014", "name_ru"=>"Камера 360°","name_en"=>"Around View", "icon_url"=>"https://..."],
     *   ["code"=>"055", "name_ru"=>null,         "name_en"=>null, "icon_url"=>null],  ← неизвестный код
     * ]
     *
     * @param  string[] $codes
     * @return array[]
     */
    public function enrichCodes(array $codes): array
    {
        $catalog = $this->catalog();

        return array_values(array_map(function (string $code) use ($catalog) {
            $opt = $catalog->get($code);

            return [
                'code'     => $code,
                'name_ru'  => $opt?->name_ru,
                'name_en'  => $opt?->name_en,
                'name_kr'  => $opt?->name_kr,
                'icon_url' => $opt?->icon_url,
                'category' => $opt?->category,
            ];
        }, $codes));
    }

    /**
     * Обогатить массив лотов (из SearchResult::toArray()).
     * Мутирует ключ 'options' в каждом lot-массиве.
     *
     * @param  array[] $lots
     * @return array[]
     */
    public function enrichLots(array $lots): array
    {
        if ($this->catalog()->isEmpty()) {
            return $lots; // каталог ещё не импортирован — вернём как есть
        }

        foreach ($lots as &$lot) {
            if (!empty($lot['options']) && is_array($lot['options'])) {
                $lot['options'] = $this->enrichCodes($lot['options']);
            }
        }
        unset($lot);

        return $lots;
    }
}
