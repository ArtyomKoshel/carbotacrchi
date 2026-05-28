<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogModelGeneration extends Model
{
    protected $table = 'catalog_model_generations';

    protected $fillable = ['model_id', 'code'];

    public function catalogModel(): BelongsTo
    {
        return $this->belongsTo(CatalogModel::class, 'model_id');
    }
}
