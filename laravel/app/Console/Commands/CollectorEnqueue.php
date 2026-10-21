<?php

namespace App\Console\Commands;

use App\Ingest\CollectorMode;
use App\Models\ParseJob;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Queues the collector's full runs from parser_schedules (one schedule, in the
 * core: docs/refactor stage 4). Jobs are tagged filters.engine = "collector" so
 * the old parser never takes them (docs/adr/0007). Runs every minute; does
 * nothing in mode off.
 */
class CollectorEnqueue extends Command
{
    protected $signature = 'collector:enqueue {--dry-run : Only print what would be queued}';

    protected $description = 'Queue scheduled full runs for the collector service (parser_schedules)';

    public function handle(): int
    {
        if (! CollectorMode::collectorRuns()) {
            $this->line('collector mode is off');

            return self::SUCCESS;
        }
        $now = CarbonImmutable::now('UTC');
        foreach ((array) config('ingest.sources') as $source) {
            $reason = $this->reasonToWait((string) $source, $now);
            if ($reason !== null) {
                $this->line("{$source}: {$reason}");

                continue;
            }
            $schedule = DB::table('parser_schedules')->where('source', $source)->first();
            $filters = ['engine' => 'collector'];
            if ((int) $schedule->max_pages > 0) {
                $filters['max_pages'] = (int) $schedule->max_pages;
            }
            if (! empty($schedule->maker_filter)) {
                $filters['maker'] = $schedule->maker_filter;
            }
            if ($this->option('dry-run')) {
                $this->info("{$source}: would queue ".json_encode($filters));

                continue;
            }
            $job = ParseJob::create([
                'source' => $source,
                'type' => 'full',
                'status' => 'pending',
                'filters' => $filters,
                'triggered_by' => 'scheduler',
            ]);
            $this->info("{$source}: queued job #{$job->id}");
        }

        return self::SUCCESS;
    }

    /** Why no job is queued for this source now, or null when one is due. */
    public function reasonToWait(string $source, CarbonImmutable $now): ?string
    {
        $schedule = DB::table('parser_schedules')->where('source', $source)->first();
        if ($schedule === null || ! $schedule->enabled) {
            return 'schedule disabled';
        }
        $collectorJobs = fn () => DB::table('parse_jobs')->where('source', $source)
            ->whereRaw(CollectorMode::ENGINE_SQL." = 'collector'");
        if ($collectorJobs()->whereIn('status', ParseJob::ACTIVE_STATUSES)->exists()) {
            return 'a collector job is queued or running';
        }

        [$kind, $expr] = array_pad(explode(':', (string) $schedule->schedule, 2), 2, '');
        if ($kind === 'cron') {
            return (new CronExpression(trim($expr)))->isDue($now->toDateTimeString()) ? null : 'not due (cron)';
        }
        $minutes = (int) (trim($expr) !== '' ? $expr : $schedule->interval_minutes) ?: 60;
        $last = $collectorJobs()->max('created_at');
        if ($last !== null && CarbonImmutable::parse($last, 'UTC')->addMinutes($minutes)->gt($now)) {
            return "not due (every {$minutes} min)";
        }

        return null;
    }
}
