<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogTrim extends Model
{
    protected $table = 'catalog_trims';

    protected $fillable = ['trim_kr', 'trim_en', 'make_en', 'priority'];
}
