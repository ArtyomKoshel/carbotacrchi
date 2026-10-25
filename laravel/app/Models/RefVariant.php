<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A platform variant placed on the reference catalog (App\Catalog\CatalogLinker): Encar's
 * make, model, badge group, badge, trim → the catalog's model, generation, modification,
 * complectation. `manual` rows were set in the admin and are never read again.
 */
class RefVariant extends Model
{
    protected $guarded = [];

    protected $casts = [
        'manual' => 'boolean',
        'editions' => 'array',
        'leftover' => 'array',
        'lots' => 'integer',
    ];
}
