<?php

namespace Tests\Unit;

use App\Services\LotLifecycle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Run counting for lots:lifecycle. Pure functions, no database.
 *
 * Run: vendor/bin/phpunit (from laravel/)
 */
class LotLifecycleRunsTest extends TestCase
{
    private const MIN = 85.0;

    private static function job(int $id, string $createdAt, ?float $cov, array $over = []): array
    {
        return array_merge([
            'id'         => $id,
            'type'       => 'full',
            'status'     => 'done',
            'filters'    => null,
            'result'     => $cov === null ? null : json_encode(['coverage_pct' => $cov]),
            'created_at' => $createdAt,
        ], $over);
    }

    /** parse_jobs on prod as of 2026-10-19 (encar), newest first. */
    private static function prodHistory(): array
    {
        $bmw = ['filters' => '{"maker": "BMW", "max_pages": 2}'];
        return [
            self::job(12, '2026-10-18 16:41:10', 92.6),
            self::job(11, '2026-10-18 16:36:27', 1.1, $bmw),
            self::job(10, '2026-10-18 16:29:17', 0.0, $bmw),
            self::job(9, '2026-06-29 07:27:33', 96.0),
            self::job(8, '2026-06-24 17:42:46', 93.9),
            self::job(7, '2026-06-21 19:16:13', 94.4),
            self::job(6, '2026-06-14 18:31:13', 38.5),
            self::job(5, '2026-06-13 13:36:48', 94.9),
            self::job(4, '2026-06-10 03:52:59', 92.4),
            self::job(3, '2026-06-06 09:06:58', 97.4),
            self::job(2, '2026-06-05 08:47:07', 92.8),
            self::job(1, '2026-06-05 08:35:27', null, ['status' => 'cancelled']),
        ];
    }

    public function test_prod_history_counts_only_unfiltered_runs_above_threshold(): void
    {
        $runs = LotLifecycle::successfulRuns(self::prodHistory(), self::MIN);

        $this->assertSame([12, 9, 8, 7, 5, 4, 3, 2], array_column($runs, 'id'));
        $this->assertSame('2026-10-18 16:41:10', LotLifecycle::delistCutoff($runs)->format('Y-m-d H:i:s'));
        // 5th newest successful run is #5: the BMW slices (#10, #11) and the 38.5% run (#6) do not count.
        $this->assertSame('2026-06-13 13:36:48', LotLifecycle::purgeCutoff($runs, 5)->format('Y-m-d H:i:s'));
    }

    public function test_stalled_parser_moves_nothing(): void
    {
        // Four months without runs: cutoffs depend only on runs, not on today's date.
        $runs = LotLifecycle::successfulRuns(array_slice(self::prodHistory(), 3), self::MIN);
        $this->assertSame('2026-06-29 07:27:33', LotLifecycle::delistCutoff($runs)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-10 03:52:59', LotLifecycle::purgeCutoff($runs, 5)->format('Y-m-d H:i:s'));
    }

    public function test_purge_waits_for_enough_runs(): void
    {
        $jobs = [
            self::job(3, '2026-10-03 00:00:00', 90.0),
            self::job(2, '2026-10-02 00:00:00', 90.0),
            self::job(1, '2026-10-01 00:00:00', 90.0),
        ];
        $runs = LotLifecycle::successfulRuns($jobs, self::MIN);

        $this->assertNull(LotLifecycle::purgeCutoff($runs, 5));
        $this->assertSame('2026-10-01 00:00:00', LotLifecycle::purgeCutoff($runs, 3)->format('Y-m-d H:i:s'));
        $this->assertNull(LotLifecycle::delistCutoff([]));
        $this->assertNull(LotLifecycle::purgeCutoff([], 5));
    }

    public function test_order_of_input_does_not_matter(): void
    {
        $shuffled = self::prodHistory();
        shuffle($shuffled);
        $runs = LotLifecycle::successfulRuns($shuffled, self::MIN);

        $this->assertSame([12, 9, 8, 7, 5, 4, 3, 2], array_column($runs, 'id'));
    }

    public function test_parser_run_start_wins_over_created_at(): void
    {
        $job = self::job(20, '2026-10-20 10:00:00', null, [
            'result' => ['coverage_pct' => 91.0, 'run_started_at' => '2026-10-20 13:05:00', 'full_run' => true],
        ]);
        $this->assertSame('2026-10-20 13:05:00', LotLifecycle::runStart($job)->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', LotLifecycle::runStart($job)->getTimezone()->getName());
    }

    public static function rejections(): array
    {
        return [
            'reparse'            => [['type' => 'reparse'], 'type=reparse'],
            'error'              => [['status' => 'error'], 'status=error'],
            'cancelled'          => [['status' => 'cancelled'], 'status=cancelled'],
            'maker filter'       => [['filters' => ['maker' => 'Kia']], 'filtered (maker/max_pages)'],
            'max_pages filter'   => [['filters' => '{"max_pages": 3}'], 'filtered (maker/max_pages)'],
            'below threshold'    => [['result' => '{"coverage_pct": 84.9}'], 'coverage 84.9% < 85.0%'],
            'no coverage'        => [['result' => '{"total": 5}'], 'no coverage_pct'],
            'no result'          => [['result' => null], 'no coverage_pct'],
            'parser said no'     => [['result' => '{"coverage_pct": 99, "full_run": false}'], 'parser reported full_run=false'],
            'no start time'      => [['created_at' => null], 'no start time'],
        ];
    }

    #[DataProvider('rejections')]
    public function test_rejected_runs(array $over, string $reason): void
    {
        $this->assertSame($reason, LotLifecycle::runRejection(self::job(1, '2026-10-01 00:00:00', 90.0, $over), self::MIN));
    }

    public static function accepted(): array
    {
        return [
            'exact threshold'     => [['result' => '{"coverage_pct": 85.0}']],
            'empty filters obj'   => [['filters' => '{}']],
            'empty filters list'  => [['filters' => '[]']],
            'null filter values'  => [['filters' => '{"maker": null, "max_pages": null}']],
            'type null (old row)' => [['type' => null]],
            'parser said yes'     => [['result' => '{"coverage_pct": 90, "full_run": true}']],
        ];
    }

    #[DataProvider('accepted')]
    public function test_accepted_runs(array $over): void
    {
        $this->assertNull(LotLifecycle::runRejection(self::job(1, '2026-10-01 00:00:00', 90.0, $over), self::MIN));
    }

    public static function writeChecks(): array
    {
        return [
            // Like prod job #9: reported 96% coverage, wrote 0 lots.
            'wrote nothing'      => ['{"coverage_pct": 96, "api_total": 219766, "lots_written": 0}', 'wrote 0 of 219766 listed (< 50%)'],
            'just below half'    => ['{"coverage_pct": 96, "api_total": 1000, "lots_written": 499}', 'wrote 499 of 1000 listed (< 50%)'],
            'no api total'       => ['{"coverage_pct": 96, "lots_written": 5000}', 'wrote 5000 of 0 listed (< 50%)'],
            // Like prod job #12: 156,893 written of 194,436 listed.
            'healthy run'        => ['{"coverage_pct": 92.6, "api_total": 194436, "lots_written": 156893}', null],
            'exactly half'       => ['{"coverage_pct": 96, "api_total": 1000, "lots_written": 500}', null],
            // Rows from before the check can't be judged here; the command checks the latest run live.
            'legacy, unmeasured' => ['{"coverage_pct": 96, "api_total": 219766}', null],
        ];
    }

    #[DataProvider('writeChecks')]
    public function test_runs_must_have_written_lots(string $result, ?string $reason): void
    {
        $job = self::job(1, '2026-10-01 00:00:00', null, ['result' => $result]);
        $this->assertSame($reason, LotLifecycle::runRejection($job, self::MIN, 0.5));
    }

    public function test_successful_runs_carry_api_total(): void
    {
        $runs = LotLifecycle::successfulRuns([
            self::job(12, '2026-10-18 16:41:10', null, ['result' => '{"coverage_pct": 92.6, "api_total": 194436, "lots_written": 156893}']),
            self::job(9, '2026-06-29 07:27:33', null, ['result' => '{"coverage_pct": 96, "api_total": 219766, "lots_written": 0}']),
        ], self::MIN, 0.5);

        $this->assertSame([12], array_column($runs, 'id'));
        $this->assertSame(194436, $runs[0]['api_total']);
    }
}
