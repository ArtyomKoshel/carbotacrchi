<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogModel extends Model
{
    protected $table = 'catalog_models';

    protected $fillable = ['make_kr', 'make_en', 'model_group_kr', 'model_kr'];

    public function generations(): HasMany
    {
        return $this->hasMany(CatalogModelGeneration::class, 'model_id');
    }
}
