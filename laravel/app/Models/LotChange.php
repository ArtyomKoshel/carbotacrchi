<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LotChange extends Model
{
    protected $table = 'lot_changes';
    public $timestamps = false;

    protected $casts = [
        'changes'     => 'array',
        'recorded_at' => 'datetime',
    ];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'lot_id');
    }
}
