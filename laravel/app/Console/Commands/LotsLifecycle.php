<?php

namespace App\Console\Commands;

use App\Services\LotLifecycle;
use Illuminate\Console\Command;

/**
 * Hide, archive and delete lots that successful full Encar runs stop seeing.
 *
 *   1. delist: active lots not seen in the latest successful full run → is_active=0
 *      (the parser already does this at the end of each run; this catches up).
 *   2. purge:  inactive lots not seen in the last N successful full runs →
 *      compact row in lots_archive, then delete the lot with all children.
 *
 * Counted in runs, not days (see App\Services\LotLifecycle). Scheduled hourly in
 * routes/console.php and skipped while a parse job is running; also stops
 * between batches if a job starts.
 */
class LotsLifecycle extends Command
{
    protected $signature = 'lots:lifecycle
        {--dry-run : Print what would change, per rule and per table, without writing}
        {--source=encar : Lot source}
        {--runs= : Purge after this many successful full runs (default config lifecycle.purge_after_runs)}
        {--min-coverage= : Coverage % for a full run to count (default config lifecycle.min_coverage)}
        {--min-write-ratio= : Share of API-listed lots a run must really write to count (default config lifecycle.min_write_ratio)}
        {--batch= : Lots per transaction (default config lifecycle.batch)}
        {--pause-ms= : Pause between transactions (default config lifecycle.pause_ms)}
        {--orphan-options : Also delete lot_options rows whose lot no longer exists (one-off cleanup)}';

    protected $description = 'Delist lots missing from the latest successful full run; archive and purge lots missing from the last N';

    public function handle(): int
    {
        $dry      = (bool) $this->option('dry-run');
        $source   = (string) $this->option('source');
        $runsN    = (int) ($this->option('runs') ?? config('lifecycle.purge_after_runs'));
        $minCov   = (float) ($this->option('min-coverage') ?? config('lifecycle.min_coverage'));
        $minWrite = (float) ($this->option('min-write-ratio') ?? config('lifecycle.min_write_ratio'));
        $batch    = max(1, (int) ($this->option('batch') ?? config('lifecycle.batch')));
        $pauseMs  = max(0, (int) ($this->option('pause-ms') ?? config('lifecycle.pause_ms')));

        if (LotLifecycle::parseJobRunning()) {
            if (!$dry) {
                $this->warn('A parse job is running; skipping (lots:lifecycle never writes during a run).');
                return self::SUCCESS;
            }
            $this->warn('A parse job is running; the counts below may change when it finishes.');
        }

        $lc = new LotLifecycle($source, $batch, $pauseMs);
        $runs = LotLifecycle::successfulRuns($lc->loadJobs(), $minCov, $minWrite);
        $delistCutoff = LotLifecycle::delistCutoff($runs);
        $purgeCutoff = LotLifecycle::purgeCutoff($runs, $runsN);

        $this->printRuns($lc, $runs, $runsN, $minCov, $minWrite);

        // Both steps trust the latest run's start as "seen since". Check live that
        // it really wrote lots: a run whose upserts silently failed would
        // otherwise hide (and later delete) the whole catalog.
        if ($runs) {
            $latest  = $runs[0];
            $written = $lc->writtenSince($latest['started_at']);
            $need    = (int) ceil($latest['api_total'] * $minWrite);
            if ($latest['api_total'] <= 0 || $written < $need) {
                $msg = sprintf(
                    'Latest successful run #%d wrote only %s lots since its start (need %s = %d%% of %s listed); skipping delist and purge.',
                    $latest['id'], number_format($written), number_format($need),
                    (int) round($minWrite * 100), number_format($latest['api_total']),
                );
                $this->error($msg);
                logger()->warning('[lots:lifecycle] ' . $msg);
                return self::SUCCESS;
            }
            $this->line(sprintf('Latest run #%d wrote %s lots (need %s): OK.', $latest['id'], number_format($written), number_format($need)));
        }

        // Stop between batches if a parse job has started meanwhile.
        $stopped = false;
        $shouldStop = function () use (&$stopped) {
            if (!$stopped && LotLifecycle::parseJobRunning()) {
                $stopped = true;
                $this->warn('A parse job started; stopping after the current batch.');
            }
            return $stopped;
        };

        $rows = [];

        // ── 1. delist ──
        if ($delistCutoff === null) {
            $this->line('delist: no successful full run yet, nothing to hide.');
        } else {
            $n = $dry ? $lc->countDelist($delistCutoff) : $lc->delist($delistCutoff, $shouldStop);
            $rows[] = ['delist', 'lots (is_active 1→0)', $n];
            $rows[] = ['delist', "lot_changes (insert 'delisted')", $n];
        }

        // ── 2. purge ──
        if ($purgeCutoff === null) {
            $this->line(sprintf('purge: %d of %d successful full runs so far, nothing to delete yet.', count($runs), $runsN));
        } elseif (!$stopped) {
            $counts = $dry
                ? $lc->countPurge($purgeCutoff, $delistCutoff)
                : $lc->purge($purgeCutoff, $shouldStop);
            $rows[] = ['purge', 'lots_archive (insert)', $counts['lots_archive']];
            $rows[] = ['purge', 'lots (delete)', $counts['lots']];
            $rows[] = ['purge', 'lot_photos (FK cascade)', $counts['lot_photos']];
            $rows[] = ['purge', 'lot_inspections (FK cascade)', $counts['lot_inspections']];
            $rows[] = ['purge', 'lot_changes (FK cascade)', $counts['lot_changes']];
            $rows[] = ['purge', 'lot_options (delete, no FK)', $counts['lot_options']];
            if (isset($counts['kept_for_favorites'])) {
                $rows[] = ['purge', 'lots kept: in favorites', $counts['kept_for_favorites']];
            }
        }

        // ── 3. orphan lot_options (one-off) ──
        if ($this->option('orphan-options') && !$stopped) {
            $o = $lc->orphanOptions(!$dry, $shouldStop);
            $rows[] = ['orphans', 'lot_options (delete, lot gone)', $o['rows']];
            $rows[] = ['orphans', 'distinct missing lot_ids', $o['lots']];
        }

        $this->newLine();
        $this->info($dry ? 'DRY RUN: nothing written. Would change:' : 'Changed:');
        $this->table(['Rule', 'Table', 'Rows'], array_map(
            fn ($r) => [$r[0], $r[1], number_format($r[2])],
            $rows,
        ));

        if (!$dry) {
            logger()->info('[lots:lifecycle] ' . implode(', ', array_map(fn ($r) => "{$r[0]}/{$r[1]}={$r[2]}", $rows))
                . ($stopped ? ' (stopped: parse job started)' : ''));
        }

        return self::SUCCESS;
    }

    private function printRuns(LotLifecycle $lc, array $runs, int $runsN, float $minCov, float $minWrite): void
    {
        $this->info(sprintf(
            'Successful full runs (coverage >= %.1f%%, no maker/max_pages filter): %d, purge after %d.',
            $minCov, count($runs), $runsN,
        ));
        if ($runs) {
            $this->table(['#', 'Job', 'Started (UTC)', 'Coverage %', 'Role'], array_map(
                fn ($r, $i) => [
                    $i + 1,
                    $r['id'],
                    $r['started_at']->format('Y-m-d H:i:s'),
                    number_format($r['coverage'], 1),
                    trim(($i === 0 ? 'delist cutoff ' : '') . ($i === $runsN - 1 ? 'purge cutoff' : '')),
                ],
                array_slice($runs, 0, max($runsN, 1)),
                array_keys(array_slice($runs, 0, max($runsN, 1))),
            ));
        }

        if ($this->output->isVerbose()) {
            $rejected = [];
            foreach ($lc->loadJobs() as $job) {
                if ($why = LotLifecycle::runRejection($job, $minCov, $minWrite)) {
                    $rejected[] = [$job['id'], $job['created_at'], $why];
                }
            }
            if ($rejected) {
                $this->line('Done full jobs that do not count:');
                $this->table(['Job', 'Created (UTC)', 'Reason'], $rejected);
            }
        }
    }
}
