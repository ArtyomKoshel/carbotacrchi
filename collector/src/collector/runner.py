"""Runs one parse_jobs row end to end and reports in the formats the core reads.

Progress (parse_jobs.progress) keeps the old job_worker keys the admin shows;
the result keeps what lots:lifecycle counts (full_run, coverage_pct, api_total,
run_started_at) and adds ingest_last_id: the core must have consumed the buffer
up to it before the run counts, or not yet ingested lots would look unseen.
"""

from __future__ import annotations

import re
import time
from collections.abc import Callable
from dataclasses import dataclass
from datetime import UTC, datetime, timedelta
from typing import Any

from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncEngine

from collector import COLLECTOR_VERSION
from collector.adapters.encar.api import EncarApi
from collector.adapters.encar.pipeline import EncarPipeline, PageReport
from collector.adapters.encar.replay import refresh_lots
from collector.adapters.encar.sweep import Sweeper, SweepState
from collector.ports import Clock, HistoryStore, IngestSink, RawStore, utc_now
from collector.runlog import RunLog
from collector.selection import RuleEngine
from collector.storage import Job, JobQueue, MySqlIngestSink

IDENTIFIER = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")


def qualified(schema: str, table: str) -> str:
    """schema.table for a configured (never user-given) schema name, validated."""
    if not IDENTIFIER.fullmatch(schema):
        raise ValueError(f"not a schema name: {schema!r}")
    return f"{schema}.{table}"


async def load_rules(db: AsyncEngine, core_schema: str) -> RuleEngine:
    """parse_filters is the core's; the collector only reads it."""
    columns = "id, name, source, field, operator, value, action, priority, rule_group_id, phase, enabled"
    sql = f"SELECT {columns} FROM {qualified(core_schema, 'parse_filters')} WHERE enabled = 1"  # noqa: S608
    async with db.connect() as conn:
        rows = (await conn.execute(text(sql))).mappings()
        return RuleEngine.from_rows([dict(r) for r in rows])


async def history_cutoff(
    db: AsyncEngine, source: str, every_runs: int, now: datetime, max_age_hours: int = 0
) -> datetime | None:
    """Histories checked before this are fetched again: the oldest 1/every_runs of the store (the
    old ENCAR_HISTORY_EVERY_RUNS), and every one older than max_age_hours, whichever is later.
    None: fetch all (an empty store, or every_runs <= 1)."""
    if every_runs <= 1:
        return None
    by_runs = await _oldest_share_cutoff(db, source, every_runs, now)
    if by_runs is None:
        return None
    return max(by_runs, now - timedelta(hours=max_age_hours)) if max_age_hours > 0 else by_runs


async def _oldest_share_cutoff(
    db: AsyncEngine, source: str, every_runs: int, now: datetime
) -> datetime | None:
    async with db.connect() as conn:
        since = (now - timedelta(days=30)).replace(tzinfo=None)
        n = (
            await conn.execute(
                text("SELECT COUNT(*) FROM lot_history WHERE source = :s AND checked_at >= :since"),
                {"s": source, "since": since},
            )
        ).scalar_one()
        if not n:
            return None
        row = (
            await conn.execute(
                text(
                    "SELECT checked_at FROM lot_history WHERE source = :s AND checked_at >= :since"
                    " ORDER BY checked_at LIMIT 1 OFFSET :k"
                ),
                {"s": source, "since": since, "k": int(n) // every_runs},
            )
        ).first()
    return None if row is None else row.checked_at.replace(tzinfo=UTC)


@dataclass
class RunContext:
    job: Job
    queue: JobQueue
    pipeline: EncarPipeline
    sink: IngestSink
    min_coverage_pct: float
    should_stop: Callable[[], bool]
    clock: Clock = utc_now
    proxy_bytes: Callable[[], int] = lambda: 0
    pipeline_depth: int = 3
    runlog: RunLog | None = None


def coverage_pct(seen: int, api_total: int) -> float:
    return round(seen / api_total * 100, 1) if api_total else 0.0


def progress_payload(
    state: SweepState, message: str, elapsed_s: float, proxy_bytes: int, *, resumed: bool = False
) -> dict[str, Any]:
    """`checkpoint` is in every payload (the next process resumes from it); `resumed` says whether
    this process took the job over from an earlier one."""
    pct = min(coverage_pct(state.seen, state.api_total), 100.0)
    report = PageReport(**state.report) if state.report else PageReport()
    elapsed = round(elapsed_s, 1)
    return {
        "status": f"{pct}%",
        "pct": pct,
        "phase": "search",
        "phase_progress": round(pct / 100, 3),
        "total_progress": round(pct / 100, 3),
        "found_total": state.seen,
        "api_total": state.api_total,
        "total": state.seen,
        "rejected": report.rejected,
        "history_fetched": report.history_fetched,
        "history_stored": report.history_stored,
        "errors": state.errors,
        "elapsed_s": elapsed,
        "avg_per_lot_s": round(elapsed / state.seen, 2) if state.seen else 0,
        "proxy_bytes": proxy_bytes,
        "message": message,
        "checkpoint": state.to_json(),
        "resumed": resumed,
        "collector": COLLECTOR_VERSION,
    }


async def run_full(ctx: RunContext, *, history_cutoff_at: datetime | None, current_year: int) -> str:
    """Returns the final status: done | interrupted."""
    job = ctx.job
    state = (
        SweepState.from_json(job.checkpoint)
        if job.checkpoint
        else SweepState(started_at=ctx.clock().isoformat(timespec="seconds"))
    )
    # A resumed run already wrote some lots: they are neither counted nor fetched again.
    seen = await ctx.sink.resume(job.id) if job.checkpoint else set()
    if seen:
        state.seen = len(seen)
    # Duration and proxy traffic are the run's, not this process's: a resumed run goes on counting
    # from its start (the pause of a deploy included) and from the bytes of the earlier processes.
    started_at = datetime.fromisoformat(state.started_at)
    earlier_bytes = state.proxy_bytes

    def elapsed() -> float:
        return max(0.0, (ctx.clock() - started_at).total_seconds())

    def proxy_bytes() -> int:
        return earlier_bytes + ctx.proxy_bytes()

    async def on_progress(s: SweepState, message: str) -> None:
        s.proxy_bytes = proxy_bytes()
        await ctx.queue.progress(
            job.id,
            progress_payload(s, message, elapsed(), s.proxy_bytes, resumed=job.checkpoint is not None),
        )
        if ctx.runlog:
            await ctx.runlog.info(f"{message}: {s.seen:,}/{s.api_total:,} seen, errors {s.errors}")

    sweeper = Sweeper(
        ctx.pipeline,
        run_id=job.id,
        history_cutoff=history_cutoff_at,
        on_progress=on_progress,
        on_error=ctx.runlog.error if ctx.runlog else None,
        should_stop=ctx.should_stop,
        current_year=current_year,
        seen=seen,
        depth=ctx.pipeline_depth,
    )
    maker = job.filters.get("maker") or None
    max_pages = job.filters.get("max_pages") or None
    await sweeper.run(state, maker=maker, max_pages=max_pages)

    state.proxy_bytes = proxy_bytes()
    if sweeper.stopped:
        await ctx.queue.interrupt(job.id, state.to_json())
        if ctx.runlog:
            await ctx.runlog.warning(f"stopped after {state.seen:,} lots; resumes from the checkpoint")
        return "interrupted"

    coverage = coverage_pct(state.seen, state.api_total)
    report = PageReport(**state.report) if state.report else PageReport()
    result = {
        "total": state.seen,
        "api_total": state.api_total,
        "coverage_pct": coverage,
        "full_run": not maker and not max_pages and coverage >= ctx.min_coverage_pct,
        "run_started_at": datetime.fromisoformat(state.started_at).strftime("%Y-%m-%d %H:%M:%S"),
        "ingest_last_id": ctx.sink.last_id if isinstance(ctx.sink, MySqlIngestSink) else None,
        "lots_emitted": report.emitted,
        "rejected": report.rejected + report.early_rejected,
        "history_fetched": report.history_fetched,
        "history_stored": report.history_stored,
        "history_failed": report.history_failed,
        "history_listing_changed": report.history_listing_changed,
        "detail_failed": report.detail_failed,
        "errors": state.errors,
        "error_log": state.error_log,
        "pages": state.pages,
        "proxy_bytes": state.proxy_bytes,
        "elapsed_s": round(elapsed(), 1),
        "collector": COLLECTOR_VERSION,
    }
    await ctx.queue.finish(
        job.id,
        "done",
        result,
        progress={
            **progress_payload(
                state, "done", elapsed(), state.proxy_bytes, resumed=job.checkpoint is not None
            ),
            "status": "done",
        },
    )
    if ctx.runlog:
        # Each error went to the log as it happened (Sweeper.on_error); here only their count.
        await ctx.runlog.info(
            f"done: {state.seen:,}/{state.api_total:,} lots ({coverage}%), full_run={result['full_run']},"
            f" errors {state.errors},"
            f" rejected {result['rejected']:,}, history fetched {report.history_fetched:,}"
            f" / stored {report.history_stored:,}"
        )
    return "done"


async def run_reparse(ctx: RunContext, raw: RawStore) -> str:
    """Admin 'Перепарсить': fresh history and inspection for the job's lots (old reparse semantics)."""
    job = ctx.job
    ids = list(dict.fromkeys(job.target_lot_ids))
    started = time.monotonic()
    report = PageReport()
    missing: list[str] = []
    for i in range(0, len(ids), 20):
        chunk_report, chunk_missing = await refresh_lots(raw, ctx.pipeline, ids[i : i + 20], run_id=job.id)
        report = report.plus(chunk_report)
        missing += chunk_missing
        done = min(i + 20, len(ids))
        await ctx.queue.progress(
            job.id,
            {"status": f"{round(done / len(ids) * 100, 1)}%", "phase": "reparse", "total": done,
             "api_total": len(ids), "message": f"{done}/{len(ids)}", "collector": COLLECTOR_VERSION},
        )  # fmt: skip
    result = {
        "type": "reparse",
        "total": len(ids),
        "refreshed": report.listed,
        "history_failed": report.history_failed,
        "rejected": report.rejected,
        "not_in_raw_store": missing[:200],
        "ingest_last_id": ctx.sink.last_id if isinstance(ctx.sink, MySqlIngestSink) else None,
        "elapsed_s": round(time.monotonic() - started, 1),
        "collector": COLLECTOR_VERSION,
    }
    await ctx.queue.finish(job.id, "done", result, progress={"status": "done", "phase": "reparse"})
    if ctx.runlog:
        await ctx.runlog.info(
            f"reparse done: {report.listed}/{len(ids)} refreshed, {len(missing)} not in the raw store yet"
        )
    return "done"


def build_pipeline(
    api: EncarApi,
    rules: RuleEngine,
    history: HistoryStore,
    sink: IngestSink,
    *,
    history_concurrency: int,
    raw: RawStore | None = None,
) -> EncarPipeline:
    return EncarPipeline(api, rules, history, sink, history_concurrency=history_concurrency, raw=raw)
