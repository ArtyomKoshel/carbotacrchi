<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxonomyTerm extends Model
{
    protected $table = 'taxonomy_terms';

    protected $fillable = [
        'source',
        'term_type',
        'term',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'priority' => 'int',
        'is_active' => 'bool',
    ];
}
