<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot lifecycle: hide lots that successful full runs stop seeing, then archive
 * and delete them once they have been missing for N successful full runs.
 *
 * Everything is counted in successful full runs (parse_jobs), never in days:
 * if the parser stalls for months, nothing is hidden or deleted.
 *
 * A successful full run is a parse_jobs row with type=full, status=done, no
 * maker/max_pages filter and result.coverage_pct >= min_coverage. The parser
 * applies the same rule before it delists (parser/lifecycle.py); keep the two
 * in sync. "Seen in a run" means parsed_at >= that run's start, because upsert
 * stamps parsed_at on every lot a run sees.
 *
 * Children of a purged lot: lot_inspections and lot_changes go by FK ON DELETE
 * CASCADE. The old pivots (lot_photos with FK, lot_options without) are handled
 * only while they still exist — they are dropped by 2026_10_20_000001.
 * Lots referenced by favorites are never purged.
 */
class LotLifecycle
{
    public function __construct(
        private readonly string $source = 'encar',
        private readonly int $batch = 2000,
        private readonly int $pauseMs = 200,
    ) {}

    // ── Run counting (pure) ─────────────────────────────────────────────────

    /**
     * Why a parse_jobs row is not a successful full run, or null when it is.
     *
     * @param array{type?:?string, status?:string, filters?:mixed, result?:mixed, created_at?:mixed} $job
     */
    public static function runRejection(array $job, float $minCoverage, float $minWriteRatio = 0.5): ?string
    {
        $type = ($job['type'] ?? null) ?: 'full';
        if ($type !== 'full') {
            return "type={$type}";
        }
        $status = $job['status'] ?? null;
        if ($status !== 'done') {
            return 'status=' . ($status ?? 'null');
        }
        $filters = self::decode($job['filters'] ?? null);
        if (!empty($filters['maker']) || !empty($filters['max_pages'])) {
            return 'filtered (maker/max_pages)';
        }
        $result = self::decode($job['result'] ?? null);
        // Set by parsers since the lifecycle change; older rows fall back to the rule below.
        if (array_key_exists('full_run', $result) && $result['full_run'] === false) {
            return 'parser reported full_run=false';
        }
        if (!isset($result['coverage_pct']) || !is_numeric($result['coverage_pct'])) {
            return 'no coverage_pct';
        }
        $coverage = (float) $result['coverage_pct'];
        if ($coverage < $minCoverage) {
            return sprintf('coverage %.1f%% < %.1f%%', $coverage, $minCoverage);
        }
        // Parsers record lots_written (parsed_at >= run start, read back from the
        // DB) since the write check. Older rows can't be checked here; the
        // command verifies the latest run live instead (writtenSince).
        if (isset($result['lots_written']) && is_numeric($result['lots_written'])) {
            $apiTotal = (int) ($result['api_total'] ?? 0);
            if ($apiTotal <= 0 || (int) $result['lots_written'] < $apiTotal * $minWriteRatio) {
                return sprintf('wrote %d of %d listed (< %d%%)', (int) $result['lots_written'], $apiTotal, (int) round($minWriteRatio * 100));
            }
        }
        if (self::runStart($job) === null) {
            return 'no start time';
        }

        return null;
    }

    /**
     * Start of a run on the parsed_at clock (UTC): result.run_started_at,
     * written by the parser, else created_at. created_at is when the job was
     * queued, never later than the real start, so it errs on the safe side:
     * fewer lots look unseen.
     */
    public static function runStart(array $job): ?CarbonImmutable
    {
        $result = self::decode($job['result'] ?? null);
        $raw = $result['run_started_at'] ?? ($job['created_at'] ?? null);
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return $raw instanceof \DateTimeInterface
                ? CarbonImmutable::instance($raw)->utc()
                : CarbonImmutable::parse((string) $raw, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Successful full runs, newest first.
     *
     * @param  iterable<array> $jobs parse_jobs rows of one source, any order
     * @return list<array{id:int, started_at:CarbonImmutable, coverage:float}>
     */
    public static function successfulRuns(iterable $jobs, float $minCoverage, float $minWriteRatio = 0.5): array
    {
        $runs = [];
        foreach ($jobs as $job) {
            $job = (array) $job;
            if (self::runRejection($job, $minCoverage, $minWriteRatio) !== null) {
                continue;
            }
            $result = self::decode($job['result'] ?? null);
            $runs[] = [
                'id'         => (int) ($job['id'] ?? 0),
                'started_at' => self::runStart($job),
                'coverage'   => (float) $result['coverage_pct'],
                'api_total'  => (int) ($result['api_total'] ?? 0),
            ];
        }
        usort($runs, fn ($a, $b) => [$b['started_at'], $b['id']] <=> [$a['started_at'], $a['id']]);

        return $runs;
    }

    /** Active lots with parsed_at before this were not seen in the latest successful run. */
    public static function delistCutoff(array $runs): ?CarbonImmutable
    {
        return $runs[0]['started_at'] ?? null;
    }

    /**
     * Inactive lots with parsed_at before this were not seen in any of the
     * last $n successful runs. Null until $n successful runs exist.
     */
    public static function purgeCutoff(array $runs, int $n): ?CarbonImmutable
    {
        if ($n < 1 || count($runs) < $n) {
            return null;
        }

        return $runs[$n - 1]['started_at'];
    }

    private static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    // ── Database ────────────────────────────────────────────────────────────

    /** parse_jobs rows of this source that could be full runs. */
    public function loadJobs(): array
    {
        return DB::table('parse_jobs')
            ->where('source', $this->source)
            ->where(fn ($q) => $q->where('type', 'full')->orWhereNull('type'))
            ->where('status', 'done')
            ->orderByDesc('id')
            ->get(['id', 'type', 'status', 'filters', 'result', 'created_at'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    public static function parseJobRunning(): bool
    {
        return DB::table('parse_jobs')->where('status', 'running')->exists();
    }

    /** Lots of this source written since $since (parsed_at >= $since), any is_active. */
    public function writtenSince(CarbonImmutable $since): int
    {
        return DB::table('lots')
            ->where('source', $this->source)
            ->where('parsed_at', '>=', self::ts($since))
            ->count();
    }

    /** Active lots not seen since $cutoff. */
    private function delistQuery(CarbonImmutable $cutoff)
    {
        return DB::table('lots')
            ->where('source', $this->source)
            ->where('is_active', 1)
            ->where(fn ($q) => $q->where('parsed_at', '<', self::ts($cutoff))->orWhereNull('parsed_at'));
    }

    public function countDelist(CarbonImmutable $cutoff): int
    {
        return $this->delistQuery($cutoff)->count();
    }

    /**
     * Hide active lots the latest successful run did not see. The parser does
     * this at the end of each run; this catches up when that step failed or
     * the run predates it.
     *
     * @param Closure():bool $shouldStop checked before every batch
     */
    public function delist(CarbonImmutable $cutoff, Closure $shouldStop): int
    {
        $total = 0;
        while (!$shouldStop()) {
            $affected = DB::transaction(function () use ($cutoff) {
                $ids = $this->delistQuery($cutoff)->limit($this->batch)
                    ->lockForUpdate()->pluck('id')->all();
                if (!$ids) {
                    return 0;
                }
                $now = now()->utc()->toDateTimeString();
                $affected = DB::table('lots')->whereIn('id', $ids)->update(['is_active' => 0, 'updated_at' => $now]);
                $changes = json_encode(['is_active' => ['old' => true, 'new' => false]]);
                DB::table('lot_changes')->insert(array_map(fn ($id) => [
                    'lot_id'      => $id,
                    'source'      => $this->source,
                    'event'       => 'delisted',
                    'changes'     => $changes,
                    'recorded_at' => $now,
                ], $ids));

                return $affected;
            });
            if ($affected === 0) {
                break;
            }
            $total += $affected;
            $this->pause();
        }

        return $total;
    }

    /**
     * Lots to purge: not seen since $cutoff and inactive. With $projectDelistBefore
     * (dry-run) also lots the delist step would hide first.
     */
    private function purgeQuery(CarbonImmutable $cutoff, ?CarbonImmutable $projectDelistBefore = null)
    {
        return DB::table('lots')
            ->where('lots.source', $this->source)
            ->where(fn ($q) => $q->where('lots.parsed_at', '<', self::ts($cutoff))->orWhereNull('lots.parsed_at'))
            ->where(function ($q) use ($projectDelistBefore) {
                $q->where('lots.is_active', 0);
                if ($projectDelistBefore) {
                    $q->orWhere('lots.parsed_at', '<', self::ts($projectDelistBefore))
                      ->orWhereNull('lots.parsed_at');
                }
            });
    }

    private static function withoutFavorites($query)
    {
        return $query->whereNotExists(fn ($q) => $q->from('favorites')->whereColumn('favorites.lot_id', 'lots.id'));
    }

    /**
     * Row counts the purge would touch, per table.
     *
     * @return array<string,int>
     */
    public function countPurge(CarbonImmutable $cutoff, ?CarbonImmutable $projectDelistBefore = null): array
    {
        $base = fn () => $this->purgeQuery($cutoff, $projectDelistBefore);
        $lots = fn () => self::withoutFavorites($base());
        $children = fn (string $table) => DB::table($table)
            ->joinSub($lots()->select('lots.id'), 'p', 'p.id', '=', "{$table}.lot_id")
            ->count();

        $total = $base()->count();
        $purge = $lots()->count();

        return [
            'lots'               => $purge,
            'lots_archive'       => $purge,
            'lot_photos'         => Schema::hasTable('lot_photos') ? $children('lot_photos') : 0,
            'lot_inspections'    => $children('lot_inspections'),
            'lot_changes'        => $children('lot_changes'),
            'lot_options'        => Schema::hasTable('lot_options') ? $children('lot_options') : 0,
            'kept_for_favorites' => $total - $purge,
        ];
    }

    /**
     * Archive and delete inactive lots not seen since $cutoff, $batch per
     * transaction.
     *
     * @param  Closure():bool $shouldStop checked before every batch
     * @return array<string,int> rows touched per table
     */
    public function purge(CarbonImmutable $cutoff, Closure $shouldStop): array
    {
        $totals = ['lots' => 0, 'lots_archive' => 0, 'lot_photos' => 0, 'lot_inspections' => 0, 'lot_changes' => 0, 'lot_options' => 0];
        $hasOptions = Schema::hasTable('lot_options');
        // Both pivots are gone after 2026_10_20_000001; only count what still exists.
        $childTables = array_values(array_filter(
            ['lot_photos', 'lot_inspections', 'lot_changes'],
            fn (string $t) => $t !== 'lot_photos' || Schema::hasTable('lot_photos'),
        ));

        while (!$shouldStop()) {
            $candidates = self::withoutFavorites($this->purgeQuery($cutoff))
                ->limit($this->batch)->pluck('lots.id')->all();
            if (!$candidates) {
                break;
            }

            $done = DB::transaction(function () use ($candidates, $cutoff, $hasOptions, $childTables) {
                // Re-check under row locks: nothing may have changed, but a lot
                // re-activated or favourited meanwhile must survive.
                $ids = self::withoutFavorites($this->purgeQuery($cutoff))
                    ->whereIn('lots.id', $candidates)->lockForUpdate()->pluck('lots.id')->all();
                if (!$ids) {
                    return null;
                }
                $in = implode(',', array_fill(0, count($ids), '?'));

                $counts = ['lot_photos' => 0];
                foreach ($childTables as $t) {
                    $counts[$t] = DB::table($t)->whereIn('lot_id', $ids)->count();
                }

                $now = now()->utc()->toDateTimeString();
                DB::statement(
                    "INSERT INTO lots_archive
                        (id, source, make_en, model_group_en, model_en, year, mileage, price,
                         first_seen, last_seen, sold_detected_at, archived_at)
                     SELECT l.id, l.source, l.make_en, l.model_group_en, l.model_en, l.year, l.mileage, l.price,
                            l.created_at, l.parsed_at,
                            (SELECT MAX(c.recorded_at) FROM lot_changes c
                              WHERE c.lot_id = l.id AND c.event = 'delisted'),
                            ?
                       FROM lots l WHERE l.id IN ({$in})
                     ON DUPLICATE KEY UPDATE
                        source = VALUES(source), make_en = VALUES(make_en),
                        model_group_en = VALUES(model_group_en), model_en = VALUES(model_en),
                        year = VALUES(year), mileage = VALUES(mileage), price = VALUES(price),
                        first_seen = COALESCE(LEAST(lots_archive.first_seen, VALUES(first_seen)), lots_archive.first_seen, VALUES(first_seen)),
                        last_seen = VALUES(last_seen), sold_detected_at = VALUES(sold_detected_at),
                        archived_at = VALUES(archived_at)",
                    [$now, ...$ids],
                );
                $counts['lots_archive'] = count($ids);

                $counts['lot_options'] = $hasOptions ? DB::table('lot_options')->whereIn('lot_id', $ids)->delete() : 0;
                $counts['lots'] = DB::table('lots')->whereIn('id', $ids)->delete();

                return $counts;
            });

            if ($done === null || $done['lots'] === 0) {
                break;
            }
            foreach ($done as $k => $v) {
                $totals[$k] += $v;
            }
            $this->pause();
        }

        return $totals;
    }

    /**
     * lot_options rows whose lot no longer exists (lot_options has no FK).
     * Walks distinct lot_ids in id order, $scan at a time.
     *
     * @param  Closure():bool $shouldStop checked before every chunk
     * @return array{lots:int, rows:int}
     */
    public function orphanOptions(bool $apply, Closure $shouldStop, int $scan = 5000): array
    {
        $found = ['lots' => 0, 'rows' => 0];
        if (!Schema::hasTable('lot_options')) {
            return $found;
        }
        $after = '';
        while (!$shouldStop()) {
            $lotIds = DB::table('lot_options')->where('lot_id', '>', $after)
                ->orderBy('lot_id')->distinct()->limit($scan)->pluck('lot_id')->all();
            if (!$lotIds) {
                break;
            }
            $after = end($lotIds);

            $existing = DB::table('lots')->whereIn('id', $lotIds)->pluck('id')->all();
            $orphans = array_values(array_diff($lotIds, $existing));
            if (!$orphans) {
                continue;
            }
            $found['lots'] += count($orphans);
            foreach (array_chunk($orphans, $this->batch) as $chunk) {
                if ($apply) {
                    $found['rows'] += DB::table('lot_options')->whereIn('lot_id', $chunk)
                        ->whereNotExists(fn ($q) => $q->from('lots')->whereColumn('lots.id', 'lot_options.lot_id'))
                        ->delete();
                    $this->pause();
                } else {
                    $found['rows'] += DB::table('lot_options')->whereIn('lot_id', $chunk)->count();
                }
            }
        }

        return $found;
    }

    private function pause(): void
    {
        if ($this->pauseMs > 0) {
            usleep($this->pauseMs * 1000);
        }
    }

    private static function ts(CarbonImmutable $t): string
    {
        return $t->utc()->format('Y-m-d H:i:s');
    }
}
