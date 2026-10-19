"""Lot lifecycle rules: when does a run count as a "successful full run".

The parser uses these rules to decide whether to delist (hide) the lots a run
did not see. Laravel's `lots:lifecycle` command (app/Services/LotLifecycle.php)
applies the same rules to `parse_jobs` rows when it counts runs for purging, so
keep the two in sync.

Runs are counted, not calendar days: if the parser stalls, nothing is hidden or
deleted, however much time passes.
"""
from __future__ import annotations

from datetime import datetime, timezone


def coverage_pct(processed: int, api_total: int) -> float:
    """Share of the API-reported listings this run processed, in percent (1 dp).

    Rounded the same way as RunResult.coverage_pct, which is what parse_jobs
    stores and what Laravel reads back.
    """
    if not api_total:
        return 0.0
    return round(processed / api_total * 100, 1)


def is_successful_full_run(
    *,
    coverage: float,
    min_coverage: float,
    maker_filter: str | None = None,
    max_pages: int | None = None,
    resumed: bool = False,
) -> bool:
    """True when a finished run saw the whole marketplace well enough to delist.

    A run limited by maker or page count only saw a slice, so lots outside the
    slice must not be hidden. A run resumed from a checkpoint skipped work it
    did before the interruption, so its start time no longer bounds what it saw.
    """
    if maker_filter or max_pages or resumed:
        return False
    return coverage >= min_coverage


def utc_now_seconds() -> datetime:
    """Naive UTC 'now' truncated to whole seconds.

    Same clock and precision as the parsed_at value LotRepository.upsert_batch
    writes, so `parsed_at < run_started_at` means "not seen in this run".
    """
    return datetime.now(timezone.utc).replace(tzinfo=None, microsecond=0)


def format_db_ts(ts: datetime) -> str:
    return ts.strftime("%Y-%m-%d %H:%M:%S")
