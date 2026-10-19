<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $table = 'notification_log';

    public const UPDATED_AT = null;

    protected $fillable = ['type', 'event_key', 'chat_id', 'ok', 'error', 'text', 'created_at'];

    protected $casts = ['ok' => 'boolean'];
}
