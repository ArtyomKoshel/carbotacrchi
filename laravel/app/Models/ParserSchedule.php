<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParserSchedule extends Model
{
    protected $table = 'parser_schedules';

    protected $fillable = [
        'source', 'enabled', 'schedule',
        'interval_minutes', 'max_pages', 'maker_filter',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
