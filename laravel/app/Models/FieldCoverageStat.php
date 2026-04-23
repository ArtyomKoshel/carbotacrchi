<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FieldCoverageStat extends Model
{
    protected $table = 'field_coverage_stats';

    protected $fillable = [
        'source', 'field_name', 'total_lots', 'filled_lots',
        'coverage_pct', 'computed_at',
    ];

    protected $casts = [
        'total_lots'   => 'integer',
        'filled_lots'  => 'integer',
        'coverage_pct' => 'float',
        'computed_at'  => 'datetime',
    ];

    public $timestamps = false;
}
