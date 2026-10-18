<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('subscriptions:check')->everyThirtyMinutes();

// Backfill model_en, generation, fuel, drive_type, trim from catalog tables.
// Hourly; skipped while a parse job runs — its table-wide UPDATEs would fight the
// parser's upserts for row locks. The first tick after the run catches up.
Schedule::command('lots:normalize-from-catalog --apply')
    ->hourlyAt(25)
    ->skip(fn () => \App\Models\ParseJob::where('status', 'running')->exists())
    ->withoutOverlapping()
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
