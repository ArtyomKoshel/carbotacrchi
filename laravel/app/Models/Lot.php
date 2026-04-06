<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lot extends Model
{
    protected $table = 'lots';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'options'    => 'array',
        'raw_data'   => 'array',
        'is_active'  => 'boolean',
        'parsed_at'  => 'datetime',
        'fetched_at' => 'datetime',
    ];
}
