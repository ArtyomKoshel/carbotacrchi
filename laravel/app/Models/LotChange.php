<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LotChange extends Model
{
    protected $table = 'lot_changes';
    public $timestamps = false;

    protected $casts = [
        'changes'     => 'array',
        'recorded_at' => 'datetime',
    ];
}
