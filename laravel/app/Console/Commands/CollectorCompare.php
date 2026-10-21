<?php

namespace App\Console\Commands;

use App\Ingest\CollectorMode;
use App\Ingest\ShadowComparison;
use App\Models\ParseJob;
use Illuminate\Console\Command;

/** Shadow mode: how a collector run differs from what the old parser wrote (docs/refactor/cutover.md). */
class CollectorCompare extends Command
{
    protected $signature = 'collector:compare
        {--job= : Collector job id (default: the latest finished one)}
        {--source=encar}
        {--json : Print the report as JSON}';

    protected $description = 'Compare a collector run with the lots the old parser wrote (shadow mode)';

    public function handle(): int
    {
        $jobId = $this->option('job') !== null ? (int) $this->option('job') : ParseJob::query()
            ->where('source', $this->option('source'))
            ->where('status', 'done')
            ->whereRaw(CollectorMode::ENGINE_SQL." = 'collector'")
            ->max('id');
        if (! $jobId) {
            $this->warn('No finished collector run.');

            return self::FAILURE;
        }

        $report = (new ShadowComparison)->compare((int) $jobId, (string) $this->option('source'));

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->info(sprintf(
            'Run #%d: %d records, %d compared, %d only in the collector, %d active only in lots',
            $report['run_id'], $report['records'], $report['compared'], $report['only_collector'], $report['only_parser_active'],
        ));
        $rows = [];
        foreach ($report['fields'] as $field => $f) {
            $example = $f['sample'][0] ?? null;
            $rows[] = [$field, $f['diff'], $f['rate'].' %', $example ? "{$example['lot_id']}: ".json_encode($example['parser'], JSON_UNESCAPED_UNICODE).' → '.json_encode($example['collector'], JSON_UNESCAPED_UNICODE) : ''];
        }
        $this->table(['Field', 'Diff', 'Rate', 'Example (parser → collector)'], $rows);

        return self::SUCCESS;
    }
}
