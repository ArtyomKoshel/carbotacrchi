<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParseJob extends Model
{
    protected $table = 'parse_jobs';

    protected $fillable = ['source', 'status', 'filters', 'progress', 'result', 'triggered_by'];

    protected $casts = [
        'filters'  => 'array',
        'progress' => 'array',
        'result'   => 'array',
    ];
}
