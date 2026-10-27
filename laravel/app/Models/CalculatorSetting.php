<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The calculator's defaults; config('calculator.defaults') fills what the admin did not set. */
class CalculatorSetting extends Model
{
    protected $table = 'calculator_settings';

    protected $primaryKey = 'key';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = ['key', 'value'];

    /** @return array<string, float> every default, stored value or config */
    public static function values(): array
    {
        $stored = static::pluck('value', 'key');
        $out = [];
        foreach (config('calculator.defaults') as $key => $def) {
            $out[$key] = (float) ($stored[$key] ?? $def['default']);
        }
        return $out;
    }
}
