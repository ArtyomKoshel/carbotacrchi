<?php

namespace App\Console\Commands;

use App\Ingest\CarLotContract;
use App\Ingest\IngestConsumer;
use Illuminate\Console\Command;

/** Applies CarLot records from the collector's buffer to `lots` (docs/adr/0003). */
class IngestConsume extends Command
{
    protected $signature = 'ingest:consume
        {--batch=500 : Records per transaction}
        {--max-batches=0 : Stop after this many batches (0 = until the buffer is drained)}';

    protected $description = 'Apply CarLot v1 records from the collector buffer to lots';

    public function handle(): int
    {
        $consumer = new IngestConsumer(CarLotContract::fromConfig());
        $result = $consumer->drain(max(1, (int) $this->option('batch')), max(0, (int) $this->option('max-batches')));

        $this->table(['Consumed', 'Inserted', 'Updated', 'Unchanged', 'Skipped', 'Rejected', 'Changes'], [array_values($result->toArray())]);

        return self::SUCCESS;
    }
}
