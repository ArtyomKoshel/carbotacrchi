<?php

namespace App\Services;

use App\Ingest\CollectorMode;
use App\Models\ParseJob;
use App\Services\Notifications\JobReport;
use Illuminate\Support\Facades\DB;

/**
 * Parser runs of one platform: launching and cancelling parse_jobs (what the
 * old /admin/jobs page did), the run the parser is busy with, successful full
 * runs as lots:lifecycle counts them, and the proxy traffic a launch will cost.
 */
class ParseRuns
{
    /** "Тестовый" run: the first pages only. */
    public const TEST_PAGES = 5;

    public const MODE_FULL  = 'full';
    public const MODE_TEST  = 'test';
    public const MODE_MAKERS = 'makers';

    /**
     * Queue runs. The Encar maker filter takes one maker, so "only these makers"
     * becomes one job per maker; the parser runs them one after another.
     *
     * @param  list<string>  $makers  Korean maker names as the platform knows them (lots.make)
     * @return list<ParseJob>
     */
    public function launch(string $source, string $mode, array $makers = [], string $triggeredBy = 'admin'): array
    {
        $filters = match ($mode) {
            self::MODE_TEST   => [['max_pages' => self::TEST_PAGES]],
            self::MODE_MAKERS => array_map(fn (string $maker) => ['maker' => $maker], array_values(array_unique($makers))),
            default           => [null],
        };

        return array_map(fn (?array $f) => ParseJob::create([
            'source'       => $source,
            'type'         => 'full',
            'status'       => 'pending',
            'filters'      => $f,
            'triggered_by' => $triggeredBy,
        ]), $filters);
    }

    /** Same as the old page: only a job that has not finished can be cancelled. */
    public function cancel(ParseJob $job): bool
    {
        return ParseJob::whereKey($job->id)
            ->whereIn('status', ParseJob::ACTIVE_STATUSES)
            ->update(['status' => 'cancelled', 'updated_at' => now()]) > 0;
    }

    /** The full run the parser is on (or about to start), newest first. */
    public function current(string $source): ?ParseJob
    {
        return ParseJob::where('source', $source)
            ->where('type', 'full')
            ->whereIn('status', ParseJob::ACTIVE_STATUSES)
            ->orderByRaw("FIELD(status, 'running', 'interrupted', 'pending')")
            ->orderByDesc('id')
            ->first();
    }

    /** Why a job is not a successful full run for lots:lifecycle, or null when it is. */
    public function rejection(ParseJob $job): ?string
    {
        return LotLifecycle::runRejection(
            $job->getAttributes(),
            (float) config('lifecycle.min_coverage'),
            (float) config('lifecycle.min_write_ratio'),
            LotLifecycle::ingestOffset(),
        );
    }

    /** The newest successful full run of a source (lots:lifecycle rules), or null. */
    public function lastSuccessful(string $source): ?ParseJob
    {
        $rows = CollectorMode::onlyFeedingJobs(DB::table('parse_jobs'))
            ->where('source', $source)
            ->where('type', 'full')
            ->where('status', 'done')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'type', 'status', 'filters', 'result', 'created_at']);

        $runs = LotLifecycle::successfulRuns(
            $rows->map(fn ($r) => (array) $r),
            (float) config('lifecycle.min_coverage'),
            (float) config('lifecycle.min_write_ratio'),
            LotLifecycle::ingestOffset(),
        );

        return $runs ? ParseJob::find($runs[0]['id']) : null;
    }

    /** The newest finished full run without filters that recorded its proxy traffic. */
    public function lastUnfilteredRun(string $source): ?ParseJob
    {
        return ParseJob::where('source', $source)
            ->where('type', 'full')
            ->where('status', 'done')
            ->whereNull('filters')
            ->whereRaw("JSON_EXTRACT(result, '$.proxy_bytes') > 0")
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Expected proxy traffic of a launch, scaled from the last unfiltered run:
     * by pages for a test run, by the makers' share of the platform's lots.
     *
     * @return array{bytes: int, basis: string}|null
     */
    public function estimateProxy(string $source, string $mode, array $makers = []): ?array
    {
        $base = $this->lastUnfilteredRun($source);
        if (!$base) {
            return null;
        }
        $bytes = (int) ($base->result['proxy_bytes'] ?? 0);
        $pages = (int) ($base->result['pages'] ?? 0);
        $basis = "по прогону #{$base->id} от " . $base->created_at?->format('d.m.Y');

        if ($mode === self::MODE_TEST) {
            if ($pages <= 0) {
                return null;
            }
            return ['bytes' => (int) round($bytes / $pages * self::TEST_PAGES), 'basis' => $basis . ', ' . self::TEST_PAGES . " из {$pages} страниц"];
        }

        if ($mode === self::MODE_MAKERS) {
            if ($makers === []) {
                return null;
            }
            // lots_make_index; the share of lots stands in for the share of traffic
            $all  = DB::table('lots')->where('source', $source)->count();
            $part = DB::table('lots')->where('source', $source)->whereIn('make', $makers)->count();
            if ($all === 0) {
                return null;
            }
            $share = $part / $all;
            return ['bytes' => (int) round($bytes * $share), 'basis' => $basis . ', доля марок в лотах ' . round($share * 100, 1) . ' %'];
        }

        return ['bytes' => $bytes, 'basis' => $basis];
    }

    /**
     * Makers to pick for a run: the Korean name the platform's filter needs,
     * labelled with the English one (translations, category make).
     *
     * @return array<string, string> make => label
     */
    public function makers(string $source): array
    {
        $makes = DB::table('lots')->where('source', $source)->distinct()->orderBy('make')->pluck('make')->all();
        $en = DB::table('translations')->where('category', 'make')->whereIn('kr', $makes)->pluck('en', 'kr');

        $options = [];
        foreach ($makes as $make) {
            $options[$make] = isset($en[$make]) && $en[$make] !== $make ? "{$en[$make]} ({$make})" : $make;
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /** @see JobReport::stats() */
    public static function stats(ParseJob $job): array
    {
        return JobReport::stats($job);
    }

    public static function gb(int $bytes): string
    {
        return number_format($bytes / 1024 ** 3, 2, ',', ' ') . ' ГБ';
    }
}
