<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParseJob extends Model
{
    protected $table = 'parse_jobs';

    protected $fillable = [
        'source', 'type', 'status', 'filters', 'target_lot_ids',
        'progress', 'result', 'triggered_by',
    ];

    protected $casts = [
        'filters'        => 'array',
        'target_lot_ids' => 'array',
        'progress'       => 'array',
        'result'         => 'array',
    ];

    /** Statuses whose job log may still be written to or resumed. */
    public const ACTIVE_STATUSES = ['pending', 'running', 'interrupted'];

    /** @return array<int, true> ids of active jobs, keyed for isset() lookups */
    public static function activeIds(): array
    {
        return static::whereIn('status', self::ACTIVE_STATUSES)
            ->pluck('id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /** Job id from a job log file name (job-12.log, job-12.log.1), or null. */
    public static function idFromLogFile(string $path): ?int
    {
        return preg_match('/^job-(\d+)\.log/', basename($path), $m) ? (int) $m[1] : null;
    }
}
