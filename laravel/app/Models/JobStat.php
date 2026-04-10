<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobStat extends Model
{
    protected $table = 'job_stats';

    public $timestamps = false;

    protected $fillable = [
        'job_id', 'source',
        'total', 'api_total', 'new_lots', 'updated_lots', 'stale_lots', 'errors', 'db_count', 'coverage_pct',
        'elapsed_s', 'search_time_s', 'enrich_time_s', 'pause_time_s', 'avg_per_lot_s',
        'pages', 'error_types', 'error_log',
    ];

    protected $casts = [
        'error_types' => 'array',
        'error_log'   => 'array',
    ];

    public function job()
    {
        return $this->belongsTo(ParseJob::class, 'job_id');
    }
}
