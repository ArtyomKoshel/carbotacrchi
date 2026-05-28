<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogGrade extends Model
{
    protected $table = 'catalog_grades';

    protected $fillable = [
        'model_id', 'grade_kr', 'fuel_type', 'drive_type',
        'engine_volume', 'seat_count', 'body_hint',
    ];

    protected $casts = [
        'engine_volume' => 'float',
        'seat_count' => 'int',
    ];

    public function model(): BelongsTo
    {
        return $this->belongsTo(CatalogModel::class, 'model_id');
    }

    public function subGrades(): HasMany
    {
        return $this->hasMany(CatalogSubGrade::class, 'grade_id');
    }
}
