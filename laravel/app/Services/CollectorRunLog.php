<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The collector's per-job log (collector.run_log, docs/adr/0001): the collector
 * runs in its own service, so its job log is a table, not a file next to
 * PARSER_LOG_FILE. Lines are rendered like the parser's: "2026-10-23 09:19:11 [INFO] …".
 */
class CollectorRunLog
{
    /**
     * Jobs that have a log, newest first.
     *
     * @return list<array{job_id: int, lines: int, last_at: string}>
     */
    public function jobs(int $limit = 50): array
    {
        try {
            return $this->db()->table('run_log')
                ->selectRaw('job_id, COUNT(*) AS line_count, MAX(at) AS last_at')
                ->groupBy('job_id')->orderByRaw('MAX(id) DESC')->limit($limit)->get()
                ->map(fn ($r) => ['job_id' => (int) $r->job_id, 'lines' => (int) $r->line_count, 'last_at' => (string) $r->last_at])
                ->all();
        } catch (Throwable) {
            return []; // no collector schema (COLLECTOR_MODE=off) or it is unreachable: nothing to list
        }
    }

    /**
     * Last matching lines; level is "ERROR", "WARNING", "INFO" or "DEBUG".
     *
     * @return array{lines: list<string>, error: ?string}
     */
    public function tail(int $jobId, int $limit = 300, ?string $level = null, ?string $search = null, bool $newestFirst = false): array
    {
        try {
            $q = $this->db()->table('run_log')->where('job_id', $jobId);
            if ($level) {
                $q->where('level', strtolower($level));
            }
            if ($search) {
                $q->where('message', 'like', '%'.addcslashes($search, '%_\\').'%');
            }
            $rows = $q->orderByDesc('id')->limit($limit)->get(['at', 'level', 'message']);
        } catch (Throwable $e) {
            return ['lines' => [], 'error' => 'Лог сборщика недоступен: '.class_basename($e)];
        }
        $lines = $rows->map(fn ($r) => substr((string) $r->at, 0, 19).' ['.strtoupper((string) $r->level).'] '.$r->message)->all();

        return ['lines' => $newestFirst ? $lines : array_reverse($lines), 'error' => null];
    }

    private function db(): ConnectionInterface
    {
        return DB::connection('collector');
    }
}
