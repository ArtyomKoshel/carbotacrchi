<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogModel extends Model
{
    protected $table = 'catalog_models';

    protected $fillable = ['make_kr', 'make_en', 'model_kr'];

    public function grades(): HasMany
    {
        return $this->hasMany(CatalogGrade::class, 'model_id');
    }
}
