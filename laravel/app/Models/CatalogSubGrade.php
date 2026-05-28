<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogSubGrade extends Model
{
    protected $table = 'catalog_sub_grades';

    protected $fillable = ['grade_id', 'sub_grade_kr', 'type'];

    public function grade(): BelongsTo
    {
        return $this->belongsTo(CatalogGrade::class, 'grade_id');
    }
}
