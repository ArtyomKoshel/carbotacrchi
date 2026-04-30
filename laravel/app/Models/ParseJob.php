<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParseJob extends Model
{
    protected $table = 'parse_jobs';

    protected $fillable = [
        'source', 'type', 'status', 'filters', 'target_lot_ids',
        'progress', 'result', 'triggered_by',
    ];

    protected $casts = [
        'filters'        => 'array',
        'target_lot_ids' => 'array',
        'progress'       => 'array',
        'result'         => 'array',
    ];
}
