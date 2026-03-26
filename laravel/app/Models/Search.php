<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Search extends Model
{
    protected $fillable = [
        'user_id', 'make', 'model', 'year_from', 'year_to',
        'price_max', 'sources', 'results_cnt',
    ];

    protected $casts = ['sources' => 'array'];
}
