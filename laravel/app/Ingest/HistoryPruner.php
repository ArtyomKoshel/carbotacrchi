<?php

namespace App\Ingest;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Deletes collector.lot_history rows (the collector's card and history store) of cars the core does
 * not show any more: a row not refreshed since $before whose lot is not active — hidden as sold, or
 * never written because a selection rule rejected the car. lots:lifecycle's purge deletes the rows of
 * the lots it deletes; a rejected car never becomes a lot, so its row would otherwise stay forever.
 *
 * A car still for sale refreshes its row at least every CHE168_CARD_MAX_AGE_HOURS (che168, 30 days)
 * or every few runs (Encar's history queue), and an active lot keeps its row at any age, so a row
 * deleted by mistake costs one fetched card. Goes through the rows in (checked_at, lot_id) windows of
 * $chunk, one short look-up and delete each.
 */
final class HistoryPruner
{
    private readonly ConnectionInterface $core;

    private readonly ConnectionInterface $collector;

    public function __construct(
        ?ConnectionInterface $core = null,
        ?ConnectionInterface $collector = null,
        private readonly int $chunk = 2000,
        private readonly int $pauseMs = 0,
    ) {
        $this->core = $core ?? DB::connection();
        $this->collector = $collector ?? DB::connection('collector');
    }

    /**
     * Deletes (or with $dryRun only counts) the rows older than $before (UTC) of cars without an
     * active lot.
     *
     * @param  (callable(): bool)|null  $shouldStop  checked before every window
     * @return array<string, array{deleted: int, kept: int}> per source
     */
    public function prune(string $before, bool $dryRun = false, ?callable $shouldStop = null): array
    {
        $counts = [];
        foreach ($this->sources($before) as $source) {
            $counts[$source] = ['deleted' => 0, 'kept' => 0];
            $after = null; // [checked_at, lot_id] of the last row looked at
            while (! ($shouldStop && $shouldStop())) {
                $rows = $this->collector->table('lot_history')
                    ->where('source', $source)->where('checked_at', '<', $before)
                    ->when($after !== null, fn ($q) => $q->where(fn ($q) => $q->where('checked_at', '>', $after[0])
                        ->orWhere(fn ($q) => $q->where('checked_at', $after[0])->where('lot_id', '>', $after[1]))))
                    ->orderBy('checked_at')->orderBy('lot_id')->limit($this->chunk)
                    ->get(['lot_id', 'checked_at']);
                if ($rows->isEmpty()) {
                    break;
                }
                $after = [$rows->last()->checked_at, $rows->last()->lot_id];

                $gone = $this->withoutActiveLot($source, $rows->pluck('lot_id')->map(fn ($id) => (string) $id)->all());
                $counts[$source]['kept'] += $rows->count() - count($gone);
                if ($gone !== []) {
                    $counts[$source]['deleted'] += $dryRun ? count($gone)
                        : $this->collector->table('lot_history')->where('source', $source)->whereIn('lot_id', $gone)->delete();
                }
                if ($this->pauseMs > 0 && ! $dryRun) {
                    usleep($this->pauseMs * 1000);
                }
            }
        }

        return $counts;
    }

    /**
     * @param  list<string>  $sourceIds
     * @return list<string> those whose lot is missing or inactive
     */
    private function withoutActiveLot(string $source, array $sourceIds): array
    {
        $active = array_flip($this->core->table('lots')->where('is_active', 1)
            ->whereIn('id', array_map(fn (string $id) => LotRowMapper::lotId($source, $id), $sourceIds))
            ->pluck('id')->all());

        return array_values(array_filter($sourceIds, fn (string $id) => ! isset($active[LotRowMapper::lotId($source, $id)])));
    }

    /** @return list<string> sources with rows older than $before */
    private function sources(string $before): array
    {
        return $this->collector->table('lot_history')->where('checked_at', '<', $before)
            ->distinct()->orderBy('source')->pluck('source')->all();
    }
}
