<?php

namespace App\Console\Commands;

use App\Models\Lot;
use Illuminate\Console\Command;

class ScoutIndexLots extends Command
{
    protected $signature   = 'scout:index-lots {--chunk=500 : Batch size}';
    protected $description = 'Index all active lots into Meilisearch (run once after setup)';

    public function handle(): int
    {
        $total = Lot::where('is_active', true)->count();
        $chunk = (int) $this->option('chunk');

        $this->info("Indexing {$total} active lots into Meilisearch (chunk={$chunk})...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Lot::where('is_active', true)
            ->chunkById($chunk, function ($lots) use ($bar): void {
                \Laravel\Scout\ModelObserver::disableSyncingFor(Lot::class);
                try {
                    $lots->searchable();
                } finally {
                    \Laravel\Scout\ModelObserver::enableSyncingFor(Lot::class);
                }
                $bar->advance($lots->count());
            });

        $bar->finish();
        $this->newLine();
        $this->info('Done. Run `php artisan scout:index lots` to configure index settings.');

        return self::SUCCESS;
    }
}
