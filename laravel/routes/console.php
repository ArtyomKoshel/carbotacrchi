<?php

use Illuminate\Support\Facades\Schedule;

// Admin Telegram notifications (jobs, DB down, balances). Registered first so it
// still runs when the DB is down and later events' DB-backed filters throw.
// The command takes its own lock in the notifications state store.
Schedule::command('notifications:watch')->everyFiveMinutes();

Schedule::command('subscriptions:check')->everyThirtyMinutes();

// Backfill model_en, generation, fuel, drive_type, trim from catalog tables.
// Hourly; skipped while a parse job runs — its table-wide UPDATEs would fight the
// parser's upserts for row locks. The first tick after the run catches up.
Schedule::command('lots:normalize-from-catalog --apply')
    ->hourlyAt(25)
    ->skip(function () {
        try {
            return \App\Models\ParseJob::where('status', 'running')->exists();
        } catch (\Throwable) {
            return true; // DB unreachable: nothing to normalize against
        }
    })
    ->withoutOverlapping()
    ->runInBackground();

// Lot lifecycle: hide lots the latest successful full run missed, archive and
// delete those missing from the last N runs. Counted in runs, so an idle parser
// changes nothing. Hourly so it lands within an hour of each (~12 h) run; skipped
// while a parse job runs, so its deletes never compete with the parser's upserts.
Schedule::command('lots:lifecycle')
    ->hourlyAt(45)
    ->skip(fn () => \App\Models\ParseJob::where('status', 'running')->exists())
    ->withoutOverlapping(120)
    ->runInBackground();

Schedule::call(function () {
    $baseFile = config('admin.log_file');
    if (!$baseFile) {
        return;
    }

    $jobDir = dirname($baseFile) . '/jobs';
    if (!is_dir($jobDir)) {
        return;
    }

    $cutoff = time() - (7 * 24 * 3600);
    $deleted = 0;
    $activeIds = \App\Models\ParseJob::activeIds();
    foreach (glob($jobDir . '/job-*.log*') ?: [] as $f) {
        $jobId = \App\Models\ParseJob::idFromLogFile($f);
        if ($jobId !== null && isset($activeIds[$jobId])) {
            continue; // an interrupted job may resume and keep logging
        }
        $mtime = @filemtime($f);
        if ($mtime !== false && $mtime < $cutoff) {
            if (@unlink($f)) {
                $deleted++;
            }
        }
    }

    if ($deleted > 0) {
        logger()->info("[logs:cleanup] deleted {$deleted} old job log file(s)");
    }
})->dailyAt('04:10')->name('logs:cleanup');

// filter_skip_log retention: drop rows whose lot no rule has skipped for
// FilterSkipLog::RETENTION_DAYS days (delisted or no longer filtered).
Schedule::command('model:prune', ['--model' => [\App\Models\FilterSkipLog::class]])
    ->dailyAt('04:20')
    ->withoutOverlapping();

// Collector ingest (docs/adr/0003). Off until the collector runs (stage 6 of docs/refactor).
Schedule::command('ingest:consume')
    ->everyMinute()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->when(fn () => (bool) config('ingest.enabled'));

// The collector's scheduled full runs (parser_schedules), tagged for it. Off in mode off.
Schedule::command('collector:enqueue')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->when(fn () => \App\Ingest\CollectorMode::collectorRuns());
