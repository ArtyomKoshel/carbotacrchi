<?php

namespace App\Services;

use App\Models\FieldCoverageStat;
use App\Models\ParseJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The «Обзор» summary of one platform. Every query uses an index except
 * "new in 24 h" (lots.created_at, ~0.2 s on 290k rows); the widget that shows
 * it loads lazily after the page.
 */
class PlatformHealth
{
    public function __construct(private readonly ParseRuns $runs) {}

    /**
     * @return array{
     *     source: string, current: ?ParseJob, last: ?ParseJob, active: int,
     *     new24: int, delisted24: int, coverage: ?float, coverage_at: ?string,
     *     series: list<array{date: string, active: int}>
     * }
     */
    public function of(string $source): array
    {
        $since = now()->subDay();
        $coverage = FieldCoverageStat::where('source', $source)
            ->selectRaw('AVG(coverage_pct) AS pct, MAX(computed_at) AS at')
            ->first();

        return [
            'source'     => $source,
            'current'    => $this->runs->current($source),
            'last'       => $this->runs->lastSuccessful($source),
            // lots_source_active_parsed_idx
            'active'     => DB::table('lots')->where('source', $source)->where('is_active', true)->count(),
            'new24'      => DB::table('lots')->where('source', $source)->where('created_at', '>=', $since)->count(),
            // lot_changes_source_recorded_idx
            'delisted24' => DB::table('lot_changes')->where('source', $source)->where('recorded_at', '>=', $since)
                ->where('event', 'delisted')->count(),
            'coverage'    => $coverage?->pct !== null ? round((float) $coverage->pct, 1) : null,
            'coverage_at' => $coverage?->at,
            'series'      => $this->activeSeries($source),
        ];
    }

    /**
     * Active lots over the last 14 days: lots are not versioned, so the points
     * are the active count each finished full run recorded (result.db_count),
     * the last run of each day, plus today's live count.
     *
     * @return list<array{date: string, active: int}>
     */
    public function activeSeries(string $source, int $days = 14): array
    {
        $from = CarbonImmutable::now()->subDays($days - 1)->startOfDay();
        $rows = ParseJob::where('source', $source)
            ->where('type', 'full')
            ->where('status', 'done')
            ->where('updated_at', '>=', $from)
            ->whereRaw("JSON_EXTRACT(result, '$.db_count') IS NOT NULL")
            ->orderBy('updated_at')
            ->get(['id', 'result', 'updated_at']);

        $byDay = [];
        foreach ($rows as $job) {
            $byDay[$job->updated_at->toDateString()] = (int) $job->result['db_count'];
        }
        $byDay[now()->toDateString()] = DB::table('lots')->where('source', $source)->where('is_active', true)->count();

        return collect($byDay)->map(fn (int $n, string $d) => ['date' => $d, 'active' => $n])->values()->all();
    }
}
