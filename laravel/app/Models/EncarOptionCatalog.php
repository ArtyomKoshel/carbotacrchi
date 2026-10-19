<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class EncarOptionCatalog extends Model
{
    protected $table      = 'encar_option_catalog';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'code', 'name_kr', 'name_en', 'name_ru', 'icon_url', 'category', 'sort_order', 'bit',
    ];

    // v2: entries carry `bit` (lots.options_mask); new key so a cache filled by
    // older code without `bit` is never read as "no bits assigned".
    private const CACHE_KEY = 'encar_option_catalog:v2';
    private const CACHE_TTL = 86400; // 24 часа

    /**
     * Каталог, закэшированный в Redis — keyBy('code').
     * Загружается один раз за запрос/воркер.
     *
     * @return Collection<string, self>
     */
    public static function getCached(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () =>
            static::orderBy('sort_order')
                ->get(['code', 'name_ru', 'name_en', 'name_kr', 'icon_url', 'category', 'bit'])
                ->keyBy('code')
        );
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
