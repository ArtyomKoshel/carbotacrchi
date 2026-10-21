<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lot extends Model
{
    protected $table     = 'lots';
    public $incrementing = false;
    protected $keyType   = 'string';

    protected $casts = [
        'options'    => 'array',
        'raw_data'   => 'array',
        'is_active'  => 'boolean',
        'parsed_at'  => 'datetime',
        'fetched_at' => 'datetime',
    ];

    /** Field changes recorded by the parser (not named changes(): Model has a $changes property). */
    public function lotChanges(): HasMany
    {
        return $this->hasMany(LotChange::class, 'lot_id');
    }

    public function inspection(): HasOne
    {
        return $this->hasOne(LotInspection::class, 'lot_id');
    }
}
