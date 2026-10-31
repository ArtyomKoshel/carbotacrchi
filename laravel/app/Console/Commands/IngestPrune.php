<?php

namespace App\Console\Commands;

use App\Ingest\IngestPruner;
use Illuminate\Console\Command;

/** Deletes collector buffer rows the core has applied (docs/adr/0003). */
class IngestPrune extends Command
{
    protected $signature = 'ingest:prune
        {--hours= : Keep applied rows this many hours (default config ingest.retention_hours)}
        {--chunk=5000 : Buffer ids per delete statement}
        {--pause-ms=50 : Pause between delete statements}
        {--dry-run : Only count the rows that would go}';

    protected $description = 'Delete collector buffer rows already applied to lots and older than the retention window';

    public function handle(): int
    {
        $hours = max(0, (int) ($this->option('hours') ?? config('ingest.retention_hours')));
        $before = gmdate('Y-m-d H:i:s', time() - $hours * 3600);
        $pruner = new IngestPruner(chunk: max(1, (int) $this->option('chunk')), pauseMs: max(0, (int) $this->option('pause-ms')));

        if ($this->option('dry-run')) {
            $this->info(sprintf('DRY RUN: %s buffer rows applied and created before %s UTC would be deleted.', number_format($pruner->count($before)), $before));

            return self::SUCCESS;
        }

        $deleted = $pruner->prune($before);
        $this->info(sprintf('Deleted %s buffer rows created before %s UTC.', number_format($deleted), $before));
        if ($deleted > 0) {
            logger()->info("[ingest:prune] deleted {$deleted} rows created before {$before}");
        }

        return self::SUCCESS;
    }
}
