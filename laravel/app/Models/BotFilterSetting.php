<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class BotFilterSetting extends Model
{
    protected $fillable = [
        'field_name',
        'field_label',
        'dtype',
        'category',
        'enabled',
        'tolerance_type',
        'tolerance_value',
        'display_in_card',
        'enum_values',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'display_in_card' => 'boolean',
        'tolerance_value' => 'float',
        'enum_values' => 'array',
    ];

    private const CACHE_KEY = 'bot_filter_settings';
    private const CACHE_TTL = 60;
    private const PROMPT_CACHE_KEY = 'bot_filter:system_prompt';

    /**
     * @return self[]
     */
    public static function allEnabled(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY . ':enabled', self::CACHE_TTL, function () {
                return self::where('enabled', true)
                    ->orderBy('sort_order')
                    ->orderBy('field_name')
                    ->get()
                    ->all();
            });
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, array{type: string, value: float}>
     */
    public static function getTolerances(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY . ':tolerances', self::CACHE_TTL, function () {
                return self::where('enabled', true)
                    ->whereIn('tolerance_type', ['absolute', 'percentage'])
                    ->whereNotNull('tolerance_value')
                    ->get()
                    ->mapWithKeys(fn (self $setting) => [
                        $setting->field_name => [
                            'type' => $setting->tolerance_type,
                            'value' => (float) $setting->tolerance_value,
                        ],
                    ])
                    ->toArray();
            });
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return string[]
     */
    public static function getCardFields(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY . ':card_fields', self::CACHE_TTL, function () {
                return self::where('display_in_card', true)
                    ->orderBy('sort_order')
                    ->orderBy('field_name')
                    ->pluck('field_name')
                    ->toArray();
            });
        } catch (\Throwable) {
            return [];
        }
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY . ':enabled');
        Cache::forget(self::CACHE_KEY . ':tolerances');
        Cache::forget(self::CACHE_KEY . ':card_fields');
        Cache::forget(self::PROMPT_CACHE_KEY);
    }
}
