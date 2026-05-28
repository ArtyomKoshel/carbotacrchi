<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogTokenMap extends Model
{
    protected $table = 'catalog_token_maps';

    protected $fillable = ['token', 'token_type', 'mapped_value'];
}
