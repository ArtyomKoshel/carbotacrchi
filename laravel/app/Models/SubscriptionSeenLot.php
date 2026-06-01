<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionSeenLot extends Model
{
    public $timestamps = false;
    public $incrementing = false;

    protected $primaryKey = ['subscription_id', 'lot_id'];

    protected $fillable = ['subscription_id', 'lot_id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
