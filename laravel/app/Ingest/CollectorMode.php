<?php

namespace App\Ingest;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Which parser feeds `lots` (docs/adr/0007, strangler migration).
 *
 * off      the old parser only (today); jobs tagged for the collector do not exist.
 * shadow   both run. The old parser feeds lots; collector jobs (filters.engine =
 *          collector) only fill the buffer for comparison and are invisible to
 *          lots:lifecycle, the "run in progress" checks and the old scheduler.
 * primary  the collector feeds lots through the ingest buffer; only its jobs count.
 */
final class CollectorMode
{
    public const OFF = 'off';

    public const SHADOW = 'shadow';

    public const PRIMARY = 'primary';

    /** SQL: the job's engine ('parser' when the job has no tag). */
    public const ENGINE_SQL = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(filters, '$.engine')), 'parser')";

    public static function current(): string
    {
        $mode = (string) config('ingest.mode', self::OFF);

        return in_array($mode, [self::OFF, self::SHADOW, self::PRIMARY], true) ? $mode : self::OFF;
    }

    /** The engine whose jobs count for lots right now. */
    public static function feedingEngine(): string
    {
        return self::current() === self::PRIMARY ? 'collector' : 'parser';
    }

    public static function collectorRuns(): bool
    {
        return self::current() !== self::OFF;
    }

    /** Restricts a parse_jobs query to the jobs of the engine that feeds lots. */
    public static function onlyFeedingJobs(Builder $query): Builder
    {
        return $query->whereRaw(self::ENGINE_SQL.' = ?', [self::feedingEngine()]);
    }
}
