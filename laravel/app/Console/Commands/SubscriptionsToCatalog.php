<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SearchQuery;
use Illuminate\Console\Command;

/**
 * Saved subscriptions that name the make / model / generation / … as text get the reference
 * catalog's ids next to it (App\Catalog\CatalogNames reads the text the same way at search
 * time). One the catalog does not know matches no lot from now on — listed for a look.
 */
class SubscriptionsToCatalog extends Command
{
    protected $signature = 'subscriptions:to-catalog {--apply : Write the ids (default: only show)}';

    protected $description = 'Put reference catalog ids into subscriptions that name the car as text';

    private const TEXT = ['make', 'model', 'generation', 'badge', 'trim'];
    private const IDS = ['refMakeId', 'refModelId', 'refGenerationIds', 'refModificationIds', 'refComplectationIds'];

    public function handle(): int
    {
        $rows = [];
        $counts = ['ids' => 0, 'already' => 0, 'unknown' => 0];
        foreach (Subscription::query()->orderBy('id')->cursor() as $sub) {
            $query = $sub->query ?? [];
            if (! array_intersect_key($query, array_flip(self::TEXT))) {
                continue;
            }
            $search = SearchQuery::fromArray($query);
            $ids = array_intersect_key($search->toSearchArray(), array_flip(self::IDS));
            $new = [...$query, ...$ids];
            $status = match (true) {
                $search->matchesNothing => 'unknown',
                $new == $query => 'already',
                default => 'ids',
            };
            $counts[$status]++;
            if ($status === 'ids' && $this->option('apply')) {
                $sub->query = $new;
                $sub->save();
            }
            $rows[] = [$sub->id, $sub->active ? 'yes' : 'no', $sub->label(),
                json_encode($ids, JSON_UNESCAPED_UNICODE), ['ids' => 'ids added', 'already' => 'has ids', 'unknown' => 'NOT IN CATALOG'][$status]];
        }

        $this->table(['Subscription', 'Active', 'Query', 'Catalog ids', 'Result'], $rows);
        $this->info(sprintf('%d get ids%s, %d already have them, %d name a car the catalog does not know (they find nothing).',
            $counts['ids'], $this->option('apply') ? '' : ' (dry run: add --apply)', $counts['already'], $counts['unknown']));
        return self::SUCCESS;
    }
}
