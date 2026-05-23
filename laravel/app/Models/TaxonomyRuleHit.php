<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxonomyRuleHit extends Model
{
    protected $table = 'taxonomy_rule_hits';

    protected $fillable = [
        'rule_id',
        'source',
        'lot_id',
        'make',
        'model_before',
        'model_after',
        'generation_before',
        'generation_after',
        'trim_before',
        'trim_after',
        'unknown_tail',
        'applied',
    ];

    protected $casts = [
        'applied' => 'bool',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(TaxonomyRule::class, 'rule_id');
    }
}
