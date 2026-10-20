<?php

namespace App\Services;

use App\Models\ParseJob;
use Illuminate\Support\Collection;

/**
 * Queues lots for re-parsing: a parse_jobs row (type=reparse) per source with
 * the lot ids in target_lot_ids. The parser's job worker picks it up.
 */
class LotReparseQueue
{
    /** Lots per bulk request; one job re-fetches every lot from the platform. */
    public const MAX_LOTS = 500;

    /**
     * @param  iterable<object{id: string, source: ?string}>  $lots
     * @return array{queued: int, skipped: int, jobs: list<int>}
     */
    public function queue(iterable $lots, string $triggeredBy = 'admin'): array
    {
        $alreadyQueued = $this->queuedLotIds();

        $bySource = [];
        $skipped  = 0;
        foreach ($lots as $lot) {
            $id = (string) $lot->id;
            if (isset($alreadyQueued[$id])) {
                $skipped++;
                continue;
            }
            $bySource[self::sourceOf($id, $lot->source ?? null)][$id] = true;
        }

        $jobs = [];
        foreach ($bySource as $source => $ids) {
            $jobs[] = ParseJob::create([
                'source'         => $source,
                'type'           => 'reparse',
                'status'         => 'pending',
                'target_lot_ids' => array_map('strval', array_keys($ids)),
                'triggered_by'   => $triggeredBy,
            ])->id;
        }

        return [
            'queued'  => array_sum(array_map('count', $bySource)),
            'skipped' => $skipped,
            'jobs'    => $jobs,
        ];
    }

    /** @return array<string, true> lot ids already in a pending/running/interrupted reparse job */
    private function queuedLotIds(): array
    {
        return ParseJob::where('type', 'reparse')
            ->whereIn('status', ParseJob::ACTIVE_STATUSES)
            ->pluck('target_lot_ids')
            ->flatten()
            ->mapWithKeys(fn ($id) => [(string) $id => true])
            ->all();
    }

    /** Same fallback as the old admin: "source_id" ids carry their source. */
    private static function sourceOf(string $lotId, ?string $source): string
    {
        if ($source) {
            return $source;
        }
        if (str_contains($lotId, '_')) {
            return explode('_', $lotId, 2)[0];
        }

        return 'encar';
    }
}
