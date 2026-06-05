<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property string      $category  trim|color|seat_color|option|model_prefix
 * @property string      $kr
 * @property string|null $en
 * @property string|null $ru
 * @property string      $source    encar_official|manual|auto
 */
class Translation extends Model
{
    protected $table = 'translations';

    protected $fillable = ['category', 'kr', 'en', 'ru', 'source'];

    /**
     * Resolve one or more Korean equivalents for a given EN/RU value within a category.
     *
     * @return string[]
     */
    public static function resolveKorean(string $value, string $category): array
    {
        if ($value === '') {
            return [];
        }

        $lower = mb_strtolower($value);

        return static::where('category', $category)
            ->where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(en) = ?', [$lower])
                  ->orWhereRaw('LOWER(ru) = ?', [$lower]);
            })
            ->pluck('kr')
            ->toArray();
    }

    /**
     * Return all EN→KR mappings for a category (for AI prompt injection).
     *
     * @return array<string,string>  [en => kr]
     */
    public static function promptMap(string $category): array
    {
        return static::where('category', $category)
            ->whereNotNull('en')
            ->where('en', '!=', '')
            ->whereRaw('LOWER(en) != LOWER(kr)')
            ->orderBy('en')
            ->pluck('kr', 'en')
            ->toArray();
    }
}
