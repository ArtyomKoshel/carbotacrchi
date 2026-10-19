<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dedup ledger. One row per announced event (status=sent: job started/finished)
 * or incident (open → resolved: stalled job, low balance, …).
 */
class NotificationEvent extends Model
{
    protected $table = 'notification_events';

    protected $fillable = ['event_key', 'type', 'status', 'opened_at', 'last_sent_at', 'resolved_at', 'meta'];

    protected $casts = [
        'opened_at'    => 'datetime',
        'last_sent_at' => 'datetime',
        'resolved_at'  => 'datetime',
        'meta'         => 'array',
    ];
}
