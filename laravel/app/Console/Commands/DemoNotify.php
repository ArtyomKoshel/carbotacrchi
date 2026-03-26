<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\ProviderAggregator;
use App\Services\SearchQuery;
use App\Services\TelegramBot;
use Illuminate\Console\Command;

class DemoNotify extends Command
{
    protected $signature   = 'demo:notify {--telegram-id=799255534 : Telegram user ID}';
    protected $description = 'Simulate new lots found and send subscription notifications to Telegram';

    public function __construct(
        private readonly ProviderAggregator $aggregator,
        private readonly TelegramBot        $bot,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $userId = (int) $this->option('telegram-id');
        $subs   = Subscription::where('user_id', $userId)->active()->get();

        if ($subs->isEmpty()) {
            $this->error("No active subscriptions for user {$userId}. Run demo:seed first.");
            return self::FAILURE;
        }

        $this->info("Simulating new lots for {$subs->count()} subscription(s)...");

        foreach ($subs as $sub) {
            $this->simulateNewLots($sub);
        }

        $this->info('Done! Check Telegram for notifications.');
        return self::SUCCESS;
    }

    private function simulateNewLots(Subscription $sub): void
    {
        $query        = SearchQuery::fromArray($sub->query ?? []);
        $query->limit = 100;

        $result   = $this->aggregator->search($query);
        $allLots  = $result->lots;
        $knownIds = $sub->known_lot_ids ?? [];

        if (empty($allLots)) {
            $this->warn("  Sub #{$sub->id} ({$sub->label()}): no lots match query, skipping.");
            return;
        }

        $knownSet = array_flip($knownIds);
        $unknown  = array_filter($allLots, fn ($lot) => !isset($knownSet[$lot->id]));

        if (empty($unknown)) {
            $count    = min(3, count($allLots));
            $fakeLots = array_slice($allLots, 0, $count);
            $this->info("  Sub #{$sub->id} ({$sub->label()}): all lots known, re-sending {$count} as 'new'.");
        } else {
            $unknown  = array_values($unknown);
            $fakeLots = array_slice($unknown, 0, min(3, count($unknown)));
            $this->info("  Sub #{$sub->id} ({$sub->label()}): found ".count($unknown)." unknown lots, sending ".count($fakeLots).".");
        }

        $this->bot->notifyNewLots($sub->user_id, $sub->label(), $fakeLots, $sub->id, $sub->query ?? []);

        $previews = array_map(fn ($l) => [
            'id'         => $l->id,
            'make'       => $l->make,
            'model'      => $l->model,
            'year'       => $l->year,
            'price'      => $l->price,
            'imageUrl'   => $l->imageUrl,
            'sourceName' => $l->sourceName,
            'lotUrl'     => $l->lotUrl ?? null,
            'damage'     => $l->damage ?? null,
        ], $fakeLots);

        $sub->update([
            'last_checked_at'  => now(),
            'new_lots_count'   => $sub->new_lots_count + count($fakeLots),
            'new_lot_previews' => array_slice(
                array_merge($previews, $sub->new_lot_previews ?? []),
                0, 5
            ),
        ]);
    }
}
