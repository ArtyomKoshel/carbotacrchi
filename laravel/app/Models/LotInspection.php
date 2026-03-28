<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LotInspection extends Model
{
    protected $table = 'lot_inspections';

    protected $fillable = [
        'lot_id', 'source',
        'cert_no', 'inspection_date', 'valid_from', 'valid_until', 'report_url',
        'first_registration', 'inspection_mileage', 'insurance_fee',
        'has_accident', 'has_outer_damage', 'has_flood', 'has_fire', 'has_tuning',
        'accident_detail', 'outer_detail', 'details',
    ];

    protected $casts = [
        'has_accident'    => 'boolean',
        'has_outer_damage'=> 'boolean',
        'has_flood'       => 'boolean',
        'has_fire'        => 'boolean',
        'has_tuning'      => 'boolean',
        'details'         => 'array',
        'inspection_date' => 'date',
        'valid_from'      => 'date',
        'valid_until'     => 'date',
        'first_registration' => 'date',
    ];
}
