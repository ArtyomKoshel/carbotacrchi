<?php

namespace App\Ingest;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Per-run counters written by IngestConsumer (table ingest_run_stats). */
final class IngestRunStats
{
    /** @return array{inserted: int, updated: int, unchanged: int, filtered: int, rejected: int}|null null: nothing applied yet */
    public static function of(int $runId): ?array
    {
        try {
            $row = DB::table('ingest_run_stats')->where('run_id', $runId)->first();
        } catch (Throwable) {
            return null; // table not migrated yet, or no database (unit tests)
        }

        return $row === null ? null : [
            'inserted' => (int) $row->inserted,
            'updated' => (int) $row->updated,
            'unchanged' => (int) $row->unchanged,
            'filtered' => (int) $row->filtered,
            'rejected' => (int) $row->rejected,
        ];
    }
}
