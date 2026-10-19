<?php

/*
 * Lot lifecycle (lots:lifecycle): hide lots that full runs stop seeing, then
 * archive and delete them. Counted in successful full runs, never in days, so a
 * stalled parser cannot wipe live cars.
 *
 * min_coverage must match the parser's LOTS_LIFECYCLE_MIN_COVERAGE (same env var).
 */
return [
    // A full run (no maker/max_pages filter, status=done) counts as successful
    // when its coverage_pct (processed / API total) is at least this.
    'min_coverage' => (float) env('LOTS_LIFECYCLE_MIN_COVERAGE', 85),

    // ...and must really have written at least this share of its API total
    // (lots with parsed_at >= run start). June 2026 job #9 reported 96% coverage
    // but wrote 0 lots; without this check it would hide the whole catalog.
    // Same env var as the parser.
    'min_write_ratio' => (float) env('LOTS_LIFECYCLE_MIN_WRITE_RATIO', 0.5),

    // Inactive lots not seen in this many latest successful full runs are purged.
    'purge_after_runs' => (int) env('LOTS_PURGE_AFTER_RUNS', 5),

    // Lots per delete transaction, and the pause between transactions.
    'batch'    => (int) env('LOTS_LIFECYCLE_BATCH', 2000),
    'pause_ms' => (int) env('LOTS_LIFECYCLE_PAUSE_MS', 200),
];
