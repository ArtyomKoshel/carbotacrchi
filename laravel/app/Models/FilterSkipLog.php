<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per (source, source_id, rule_name), upserted by the parser:
 * skipped_at = first hit by the rule, last_skipped_at = latest hit, skip_count = hits.
 */
class FilterSkipLog extends Model
{
    use MassPrunable;

    /** Rows whose lot has not been skipped for this many days are pruned (model:prune). */
    public const RETENTION_DAYS = 30;

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
        'last_skipped_at',
        'skip_count',
    ];

    protected $casts = [
        'skipped_at'      => 'datetime',
        'last_skipped_at' => 'datetime',
        'skip_count'      => 'integer',
    ];

    public function prunable(): Builder
    {
        return static::where('last_skipped_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

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
     * Scope for filtering by date range: rows whose [skipped_at, last_skipped_at]
     * interval overlaps [from, to], i.e. the lot was being skipped in that period.
     */
    public function scopeByDateRange($query, $from, $to)
    {
        if ($from) {
            $query->where('last_skipped_at', '>=', $from);
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
