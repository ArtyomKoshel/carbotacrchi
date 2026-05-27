<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxonomyRule extends Model
{
    protected $table = 'taxonomy_rules';

    protected $fillable = [
        'source',
        'make',
        'model_contains',
        'badge_contains',
        'unknown_tail',
        'action',
        'action_value',
        'priority',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'hit_count' => 'int',
        'priority' => 'int',
        'last_hit_at' => 'datetime',
    ];

    public function hits(): HasMany
    {
        return $this->hasMany(TaxonomyRuleHit::class, 'rule_id');
    }
}
