<?php

namespace App\Console\Commands;

use App\Ingest\HistoryPruner;
use Illuminate\Console\Command;

/** Deletes the collector's stored cards and histories of cars the core does not show (App\Ingest\HistoryPruner). */
class CollectorPruneHistory extends Command
{
    protected $signature = 'collector:prune-history
        {--days= : Keep rows refreshed within this many days (default config lifecycle.history_max_age_days)}
        {--chunk=2000 : Rows per look-up and delete}
        {--pause-ms=50 : Pause between deletes}
        {--dry-run : Only count the rows that would go}';

    protected $description = 'Delete collector.lot_history rows older than the window whose car has no active lot';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?? config('lifecycle.history_max_age_days')));
        $before = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $dry = (bool) $this->option('dry-run');
        $pruner = new HistoryPruner(chunk: max(1, (int) $this->option('chunk')), pauseMs: max(0, (int) $this->option('pause-ms')));

        $counts = $pruner->prune($before, $dry);
        if ($dry) {
            $this->info("DRY RUN: nothing deleted. Rows checked before {$before} UTC:");
        }
        $this->table(['Source', $dry ? 'Would delete' : 'Deleted', 'Kept (active lot)'], array_map(
            fn (string $source, array $c) => [$source, number_format($c['deleted']), number_format($c['kept'])],
            array_keys($counts), $counts,
        ));
        $deleted = array_sum(array_column($counts, 'deleted'));
        if (! $dry && $deleted > 0) {
            logger()->info("[collector:prune-history] deleted {$deleted} rows checked before {$before}", $counts);
        }

        return self::SUCCESS;
    }
}
