<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotFilterSetting extends Model
{
    private const DEFAULT_CARD_FIELDS = [
        'source',
        'make',
        'model',
        'year',
        'price',
        'mileage',
        'fuel',
        'transmission',
        'engine_volume',
        'drive_type',
        'body_type',
        'insurance_count',
        'has_accident',
        'owners_count',
        'first_reg_date',
        'flood_history',
        'lot_url',
    ];

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

    /**
     * @return self[]
     */
    public static function allEnabled(): array
    {
        try {
            return self::where('enabled', true)
                ->orderBy('sort_order')
                ->orderBy('field_name')
                ->get()
                ->all();
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
            return self::where('display_in_card', true)
                ->orderBy('sort_order')
                ->orderBy('field_name')
                ->pluck('field_name')
                ->toArray();
        } catch (\Throwable) {
            return self::defaultCardFields();
        }
    }

    /**
     * @return string[]
     */
    public static function defaultCardFields(): array
    {
        return self::DEFAULT_CARD_FIELDS;
    }
}
