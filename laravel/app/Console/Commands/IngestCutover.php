<?php

namespace App\Console\Commands;

use App\Ingest\CollectorMode;
use App\Ingest\IngestConsumer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The step that makes the collector feed lots (docs/refactor/cutover.md).
 *
 * Shadow runs left records in the buffer that were never meant to be applied:
 * the core's read offset moves past them, and their last id is kept as the
 * cutover point, so lots:lifecycle never counts a shadow run (its lots were not
 * applied and would all look unseen).
 */
class IngestCutover extends Command
{
    protected $signature = 'ingest:cutover {--force : Run even while COLLECTOR_MODE is not primary}';

    protected $description = 'Skip the shadow-mode buffer and mark the cutover point (run once when switching to primary)';

    public function handle(): int
    {
        if (CollectorMode::current() !== CollectorMode::PRIMARY && ! $this->option('force')) {
            $this->error('COLLECTOR_MODE is not primary; set it first (or pass --force).');

            return self::FAILURE;
        }
        $last = (int) (DB::connection('collector')->table('ingest_lots')->max('id') ?? 0);
        $now = now();
        foreach ([IngestConsumer::CONSUMER, IngestConsumer::CUTOVER] as $consumer) {
            DB::table('ingest_offsets')->updateOrInsert(['consumer' => $consumer], ['last_id' => $last, 'updated_at' => $now]);
        }
        $this->info("Buffer skipped up to id {$last}; runs recorded up to it will not count for lots:lifecycle.");

        return self::SUCCESS;
    }
}
