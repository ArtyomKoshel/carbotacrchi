<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationRecipient extends Model
{
    protected $table = 'notification_recipients';

    protected $fillable = ['chat_id', 'label', 'user_id', 'enabled', 'types'];

    protected $casts = [
        'enabled' => 'boolean',
        'types'   => 'array',
    ];

    /** Types a new recipient gets unless chosen otherwise. */
    public static function defaultTypes(): array
    {
        return collect(config('notifications.types'))
            ->filter(fn ($t) => $t['default'])
            ->keys()
            ->values()
            ->all();
    }

    public function wants(string $type): bool
    {
        return $this->enabled && in_array($type, $this->types ?? [], true);
    }
}
