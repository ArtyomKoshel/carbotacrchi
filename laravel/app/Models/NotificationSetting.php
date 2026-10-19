<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Notification thresholds; defaults come from config('notifications.thresholds'). */
class NotificationSetting extends Model
{
    protected $table = 'notification_settings';

    protected $primaryKey = 'key';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = ['key', 'value'];

    /** @return array<string, float> every threshold, stored value or default */
    public static function thresholds(): array
    {
        $stored = static::pluck('value', 'key');
        $out = [];
        foreach (config('notifications.thresholds') as $key => $def) {
            $out[$key] = (float) ($stored[$key] ?? $def['default']);
        }
        return $out;
    }
}
