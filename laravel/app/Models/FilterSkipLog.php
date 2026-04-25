<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FilterSkipLog extends Model
{
    protected $table = 'filter_skip_log';
    public $timestamps = false;

    protected $fillable = [
        'source',
        'source_id',
        'lot_url',
        'rule_name',
        'rule_id',
        'action',
        'field_name',
        'field_value',
        'skipped_at',
    ];

    protected $casts = [
        'skipped_at' => 'datetime',
    ];

    /**
     * Get lot URL based on source and source_id.
     */
    public function getLotUrlAttribute(): string
    {
        if (!empty($this->attributes['lot_url'])) {
            return $this->attributes['lot_url'];
        }

        if ($this->source === 'encar') {
            return "https://fem.encar.com/cars/detail/{$this->source_id}";
        }

        if ($this->source === 'kbcha') {
            return "https://www.kbchachacha.com/public/car/detail.kbc?carSeq={$this->source_id}";
        }

        return '#';
    }

    /**
     * Scope for filtering by source.
     */
    public function scopeBySource($query, $source)
    {
        if ($source) {
            return $query->where('source', $source);
        }
        return $query;
    }

    /**
     * Scope for filtering by rule_id.
     */
    public function scopeByRule($query, $ruleId)
    {
        if ($ruleId) {
            return $query->where('rule_id', $ruleId);
        }
        return $query;
    }

    /**
     * Scope for filtering by date range.
     */
    public function scopeByDateRange($query, $from, $to)
    {
        if ($from) {
            $query->where('skipped_at', '>=', $from);
        }
        if ($to) {
            $query->where('skipped_at', '<=', $to);
        }
        return $query;
    }

    /**
     * Scope for ordering by date.
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('skipped_at', 'desc');
    }
}
