<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxonomyAnomalyQueue extends Model
{
    protected $table = 'taxonomy_anomaly_queue';

    protected $fillable = [
        'source',
        'make',
        'unknown_tail',
        'reason',
        'sample_lot_id',
        'sample_model_raw',
        'sample_payload',
        'seen_count',
        'first_seen_at',
        'last_seen_at',
        'status',
        'suggested_action',
        'suggested_value',
        'suggestion_confidence',
        'rule_id',
    ];

    protected $casts = [
        'sample_payload' => 'array',
        'seen_count' => 'int',
        'suggestion_confidence' => 'float',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(TaxonomyRule::class, 'rule_id');
    }
}
