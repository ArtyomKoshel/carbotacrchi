<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\ProviderAggregator;
use App\Services\SearchQuery;
use App\Services\TelegramBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckSubscriptions extends Command
{
    protected $signature   = 'subscriptions:check';
    protected $description = 'Check active subscriptions and notify users about new lots';

    public function __construct(
        private readonly ProviderAggregator $aggregator,
        private readonly TelegramBot        $bot,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $subs = Subscription::active()->get();

        if ($subs->isEmpty()) {
            $this->info('No active subscriptions.');
            return self::SUCCESS;
        }

        $this->info("Checking {$subs->count()} subscription(s)...");

        foreach ($subs as $sub) {
            $this->checkOne($sub);
        }

        return self::SUCCESS;
    }

    private function checkOne(Subscription $sub): void
    {
        try {
            $query        = SearchQuery::fromArray($sub->query ?? []);
            $query->limit = 100;

            $result   = $this->aggregator->search($query);
            $seenIds  = $sub->seenLots()->pluck('lot_id')->flip()->all();

            $newLots = array_values(array_filter(
                $result->lots,
                fn ($lot) => !isset($seenIds[$lot->id])
            ));

            $sub->update(['last_checked_at' => now()]);

            if (empty($newLots)) {
                return;
            }

            $this->info("  Sub #{$sub->id} (user {$sub->user_id}): {$sub->label()} → ".count($newLots).' new lot(s)');

            $this->bot->notifyNewLots($sub->user_id, $sub->label(), $newLots, $sub->id, $sub->query ?? []);

            $sub->markLotsAsSeen(array_map(fn ($l) => $l->id, $newLots));

            $previews = array_map(fn ($l) => [
                'id'         => $l->id,
                'make'       => $l->make,
                'model'      => $l->model,
                'year'       => $l->year,
                'price'      => $l->price,
                'imageUrl'   => $l->imageUrl,
                'sourceName' => $l->sourceName,
                'lotUrl'     => $l->lotUrl  ?? null,
                'damage'     => $l->damage  ?? null,
            ], array_slice($newLots, 0, 5));

            $sub->update([
                'new_lots_count'   => $sub->new_lots_count + count($newLots),
                'new_lot_previews' => array_slice(
                    array_merge($previews, $sub->new_lot_previews ?? []),
                    0, 5
                ),
            ]);
        } catch (\Throwable $e) {
            Log::error("[CheckSubscriptions] Sub #{$sub->id} failed: {$e->getMessage()}");
            $this->error("  Sub #{$sub->id} failed: {$e->getMessage()}");
        }
    }
}
