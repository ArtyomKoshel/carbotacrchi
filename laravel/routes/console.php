<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('subscriptions:check')->everyThirtyMinutes();

// Backfill model_en, generation, fuel, drive_type, trim from catalog tables.
// Runs frequently so new lots get normalized soon after the parser ingests them.
Schedule::command('lots:normalize-from-catalog --apply')
    ->everyFifteenMinutes()
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
    foreach (glob($jobDir . '/job-*.log*') ?: [] as $f) {
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
