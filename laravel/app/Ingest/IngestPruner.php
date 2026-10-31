<?php

namespace App\Ingest;

use App\Models\ParseJob;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Deletes collector.ingest_lots rows the core no longer needs (docs/adr/0003):
 * applied by the consumer (id <= its offset), older than the retention window,
 * and not written by a run that is still active — an interrupted run resumes by
 * reading its own rows back (collector MySqlIngestSink.resume).
 *
 * Every full run writes the whole catalogue (~200k records, ~1.3 GB), so
 * without this the buffer grows by that much per run.
 *
 * Deletes go by primary-key windows of $chunk ids, one short statement each,
 * so a large backlog never holds a long transaction or a big undo log.
 */
final class IngestPruner
{
    private readonly ConnectionInterface $core;

    private readonly ConnectionInterface $collector;

    public function __construct(
        ?ConnectionInterface $core = null,
        ?ConnectionInterface $collector = null,
        private readonly int $chunk = 5000,
        private readonly int $pauseMs = 0,
    ) {
        $this->core = $core ?? DB::connection();
        $this->collector = $collector ?? DB::connection('collector');
    }

    /**
     * Highest buffer id that may go: applied by the core and created before
     * $before (UTC). Null when nothing qualifies.
     */
    public function upTo(string $before): ?int
    {
        $offset = $this->core->table('ingest_offsets')->where('consumer', IngestConsumer::CONSUMER)->value('last_id');
        if ($offset === null) {
            return null;
        }
        // One collector process writes the buffer, so ids grow with created_at.
        $old = $this->collector->table('ingest_lots')->where('created_at', '<', $before)->max('id');
        if ($old === null) {
            return null;
        }
        $upTo = min((int) $offset, (int) $old);

        return $upTo > 0 ? $upTo : null;
    }

    /** Rows prune() would delete. */
    public function count(string $before): int
    {
        $upTo = $this->upTo($before);

        return $upTo === null ? 0 : $this->prunable($upTo, $this->activeRuns())->count();
    }

    /**
     * Deletes the rows count() reports; returns how many went.
     *
     * @param  (callable(): bool)|null  $shouldStop  checked before every window
     */
    public function prune(string $before, ?callable $shouldStop = null): int
    {
        $upTo = $this->upTo($before);
        if ($upTo === null) {
            return 0;
        }
        $active = $this->activeRuns();
        $from = (int) $this->collector->table('ingest_lots')->min('id');

        $deleted = 0;
        while ($from <= $upTo && ! ($shouldStop && $shouldStop())) {
            $to = min($from + $this->chunk - 1, $upTo);
            $deleted += $this->prunable($to, $active)->where('id', '>=', $from)->delete();
            $from = $to + 1;
            if ($this->pauseMs > 0 && $from <= $upTo) {
                usleep($this->pauseMs * 1000);
            }
        }

        return $deleted;
    }

    /** @param list<int> $activeRuns */
    private function prunable(int $upTo, array $activeRuns)
    {
        return $this->collector->table('ingest_lots')
            ->where('id', '<=', $upTo)
            ->when($activeRuns !== [], fn ($q) => $q->where(
                fn ($q) => $q->whereNull('run_id')->orWhereNotIn('run_id', $activeRuns),
            ));
    }

    /** @return list<int> parse_jobs ids that may still resume (their run_id in the buffer) */
    private function activeRuns(): array
    {
        return $this->core->table('parse_jobs')->whereIn('status', ParseJob::ACTIVE_STATUSES)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
