<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Favorite extends Model
{
    protected $fillable = ['user_id', 'lot_id', 'source', 'lot_data'];

    protected $casts = ['lot_data' => 'array'];
}
