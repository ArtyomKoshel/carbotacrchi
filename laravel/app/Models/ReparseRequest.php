<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReparseRequest extends Model
{
    protected $table = 'reparse_requests';

    protected $fillable = ['lot_id', 'status', 'result'];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
