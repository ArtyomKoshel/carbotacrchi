"""Base class for marketplace parsers.

Provides common infrastructure so concrete parsers (Encar, KBCha, ...) only
need to implement source-specific fetch/parse logic. Shared here:

  - Stats dict initialization with a consistent schema
  - Final summary log + dict in the exact format the scheduler expects
  - Human-readable elapsed time formatting ("1h 23m")
  - Coverage-guarded delist hook
  - Error-type accounting helper
  - Unified RunResult / PhaseResult / ProgressUpdate dataclasses

Concrete parsers must implement `get_source_key`, `get_source_name`, and `run`.
"""

from __future__ import annotations

import logging
import time as _time
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import Any, Callable

from repository import LotRepository

logger = logging.getLogger(__name__)


# ── Unified result types ────────────────────────────────────────────────────

@dataclass
class PhaseResult:
    """Breakdown for a single execution phase (search, enrich, inspect, delist)."""
    name: str
    lots_in: int = 0
    lots_out: int = 0
    elapsed_s: float = 0.0
    errors: int = 0


@dataclass
class RunResult:
    """Unified return value from parser.run().

    Both Encar and KBCha return this; job_worker and admin UI consume it.
    Source-specific extras go in `extra`.
    """
    new: int = 0
    updated: int = 0
    stale: int = 0
    filtered: int = 0
    errors: int = 0
    total_processed: int = 0
    total_available: int = 0      # API / site-reported total
    db_count: int = 0
    coverage_pct: float = 0.0
    elapsed_s: float = 0.0
    phases: list[PhaseResult] = field(default_factory=list)
    error_log: list[str] = field(default_factory=list)
    error_types: dict[str, int] = field(default_factory=dict)
    filter_rules: dict[str, int] = field(default_factory=dict)
    extra: dict[str, Any] = field(default_factory=dict)

    # Computed convenience fields populated by finalize_summary
    time_human: str = ""
    search_time_s: float = 0.0
    enrich_time_s: float = 0.0
    inspect_time_s: float = 0.0
    pause_time_s: float = 0.0
    avg_per_lot_s: float = 0.0
    pages: int = 0

    def to_dict(self) -> dict:
        """Serialize to dict for JSON storage (job_stats, Redis pub, etc.)."""
        return {
            "total": self.total_processed,
            "new": self.new,
            "updated": self.updated,
            "stale": self.stale,
            "filtered": self.filtered,
            "errors": self.errors,
            "api_total": self.total_available,
            "db_count": self.db_count,
            "coverage_pct": self.coverage_pct,
            "time": self.time_human,
            "elapsed_s": self.elapsed_s,
            "search_time_s": self.search_time_s,
            "enrich_time_s": self.enrich_time_s,
            "inspect_time_s": self.inspect_time_s,
            "pause_time_s": self.pause_time_s,
            "avg_per_lot_s": self.avg_per_lot_s,
            "pages": self.pages,
            "phases": [
                {"name": p.name, "lots_in": p.lots_in, "lots_out": p.lots_out,
                 "elapsed_s": round(p.elapsed_s, 1), "errors": p.errors}
                for p in self.phases
            ],
            "error_types": self.error_types,
            "error_log": self.error_log[-50:],
            "filter_rules": self.filter_rules,
            **self.extra,
        }


@dataclass
class ProgressUpdate:
    """Unified progress payload sent to job_worker / admin UI.

    Replaces the old (page, found, total_pages, stats) callback signature.
    """
    phase: str                # "search" | "enrich" | "inspect" | "delist"
    phase_progress: float     # 0.0 – 1.0 within current phase
    total_progress: float     # 0.0 – 1.0 overall
    lots_found: int = 0
    lots_processed: int = 0
    message: str = ""
    stats: dict | None = None  # raw stats dict for backward compat


# Callback type accepted by parser.run()
ProgressCallback = Callable[[ProgressUpdate], None]


# ── Legacy callback adapter ─────────────────────────────────────────────────

def _adapt_legacy_callback(
    on_page_callback,
) -> ProgressCallback | None:
    """Wrap an old-style (page, found, total_pages, stats) callback into ProgressCallback.

    Returns None if on_page_callback is None.
    """
    if on_page_callback is None:
        return None

    def _adapted(update: ProgressUpdate) -> None:
        on_page_callback(
            page=update.lots_processed,
            found=update.lots_found,
            total_pages=update.lots_found,  # best available
            stats=update.stats,
        )
    return _adapted


# Default keys in the stats dict. Parsers MAY add extra keys.
_STATS_SCHEMA: dict[str, Any] = {
    "total": 0,
    "new": 0,
    "updated": 0,
    "errors": 0,
    "filtered": 0,
    "pause_time": 0.0,
    "enrich_time": 0.0,
    "search_time": 0.0,
    "inspect_time": 0.0,
    "error_types": dict,   # factories — instantiated per run
    "error_log": list,
    "filter_rules": dict,
}


class AbstractParser(ABC):
    """Common parser scaffolding. Subclasses override get_source_key/name + run."""

    #: Minimum coverage (processed / existing-in-db) required to trigger delist.
    #: Subclasses may override (e.g. Encar sets 95.0).
    MIN_DELIST_COVERAGE: float = 80.0

    def __init__(self, repo: LotRepository):
        self.repo = repo
        self._phases: list[PhaseResult] = []

    # ── Mandatory interface ────────────────────────────────────────────────
    @abstractmethod
    def get_source_key(self) -> str:
        """Unique key used in DB lots.source, e.g. 'kbcha', 'encar'."""

    @abstractmethod
    def get_source_name(self) -> str:
        """Display name for UI / logs, e.g. 'KBChacha', 'Encar'."""

    @abstractmethod
    def run(self, **kwargs) -> RunResult | dict:
        """Run full import cycle. Return RunResult (preferred) or legacy dict."""

    def run_reenrich(self, limit: int | None = None) -> int:
        """Re-fetch detail pages for existing lots. Override if supported."""
        raise NotImplementedError(f"{self.__class__.__name__} does not support run_reenrich")

    # ── Shared helpers ─────────────────────────────────────────────────────
    @staticmethod
    def init_stats(**extra) -> dict:
        """Return a fresh stats dict with the standard schema.

        Extra keys can be passed to seed parser-specific counters
        (e.g. `init_stats(detail_fetched=0, inspect_time=0.0)`).
        """
        stats: dict[str, Any] = {}
        for key, val in _STATS_SCHEMA.items():
            stats[key] = val() if callable(val) else val
        stats.update(extra)
        return stats

    def inc_error(self, stats: dict, etype: str, message: str | None = None) -> None:
        """Increment error counters in a consistent way across parsers."""
        stats["errors"] = stats.get("errors", 0) + 1
        et = stats.setdefault("error_types", {})
        et[etype] = et.get(etype, 0) + 1
        if message:
            log = stats.setdefault("error_log", [])
            # Keep log bounded — only last 200 messages
            log.append(message)
            if len(log) > 200:
                del log[:len(log) - 200]

    @staticmethod
    def format_elapsed(seconds: float) -> str:
        """Convert raw seconds into '1h 23m' / '45m' human string."""
        hours = int(seconds // 3600)
        mins = int((seconds % 3600) // 60)
        return f"{hours}h {mins}m" if hours else f"{mins}m"

    def delist_if_complete(
        self,
        seen_ids: set[str],
        reference_total: int,
        grace_hours: int = 1,
    ) -> int:
        """Mark lots not in seen_ids as inactive, guarded by coverage threshold.

        Args:
            seen_ids: lot IDs observed during this run.
            reference_total: denominator for coverage computation — typically
                the count of currently-active DB rows OR the API-reported total.
            grace_hours: don't delist lots updated more recently than this.
        """
        source = self.get_source_key()
        coverage_pct = len(seen_ids) / reference_total * 100 if reference_total else 0.0
        if coverage_pct < self.MIN_DELIST_COVERAGE:
            logger.warning(
                f"[{source}] Skipping delist: coverage {coverage_pct:.1f}% "
                f"< {self.MIN_DELIST_COVERAGE}% "
                f"(seen {len(seen_ids):,} / ref {reference_total:,})."
            )
            return 0
        return self.repo.mark_inactive(source, seen_ids, grace_hours=grace_hours)

    # ── Phase tracking ───────────────────────────────────────────────────
    def start_phase(self, name: str, lots_in: int = 0) -> PhaseResult:
        """Begin a new phase. Returns the PhaseResult to be updated later."""
        phase = PhaseResult(name=name, lots_in=lots_in)
        phase._start_time = _time.monotonic()  # type: ignore[attr-defined]
        self._phases.append(phase)
        return phase

    @staticmethod
    def end_phase(phase: PhaseResult, lots_out: int = 0, errors: int = 0) -> None:
        """Finalize a phase with elapsed time and output counts."""
        phase.elapsed_s = round(_time.monotonic() - phase._start_time, 1)  # type: ignore[attr-defined]
        phase.lots_out = lots_out
        phase.errors = errors

    def finalize_summary(
        self,
        elapsed: float,
        stats: dict,
        seen_ids: set[str],
        api_total: int = 0,
        stale: int = 0,
        db_count: int = 0,
    ) -> RunResult:
        """Emit [STAT] summary lines and return a RunResult.

        Backward-compatible: RunResult.to_dict() returns the same keys as
        the old dict, so job_worker and admin UI keep working.
        """
        source = self.get_source_key()
        coverage_pct = stats["total"] / api_total * 100 if api_total else 0.0
        avg_per_lot = round(elapsed / stats["total"], 2) if stats["total"] else 0

        logger.info(f"[STAT] [{source}] ========== IMPORT COMPLETE ==========")
        if api_total:
            logger.info(f"[STAT] [{source}] API reported: {api_total:,}")
        logger.info(
            f"[STAT] [{source}] Processed: {stats['total']:,}"
            + (f" ({coverage_pct:.1f}% coverage)" if api_total else "")
        )
        if db_count:
            logger.info(f"[STAT] [{source}] In DB now: {db_count:,}")
        logger.info(f"[STAT] [{source}] New:      {stats.get('new', 0)}")
        logger.info(f"[STAT] [{source}] Updated:  {stats.get('updated', 0)}")
        logger.info(f"[STAT] [{source}] Stale:    {stale}")
        logger.info(f"[STAT] [{source}] Filtered: {stats.get('filtered', 0)}")
        logger.info(f"[STAT] [{source}] Errors:   {stats.get('errors', 0)}")
        logger.info(f"[STAT] [{source}] Time:     {elapsed:.1f}s")
        if "search_time" in stats:
            logger.info(f"[STAT] [{source}] Search:   {stats['search_time']:.1f}s")
        if "enrich_time" in stats:
            logger.info(f"[STAT] [{source}] Enrich:   {stats['enrich_time']:.1f}s")
        if "inspect_time" in stats:
            logger.info(f"[STAT] [{source}] Inspect:  {stats['inspect_time']:.1f}s")
        if "pause_time" in stats:
            logger.info(f"[STAT] [{source}] Pauses:   {stats['pause_time']:.1f}s")
        if stats.get("error_types"):
            logger.info(f"[STAT] [{source}] Err types: {stats['error_types']}")
        if stats.get("filter_rules"):
            logger.info(f"[STAT] [{source}] Filter hits: {stats['filter_rules']}")
        if self._phases:
            logger.info(f"[STAT] [{source}] Phases:")
            for p in self._phases:
                logger.info(f"[STAT] [{source}]   {p.name}: in={p.lots_in} out={p.lots_out} err={p.errors} {p.elapsed_s:.1f}s")

        result = RunResult(
            new=stats.get("new", 0),
            updated=stats.get("updated", 0),
            stale=stale,
            filtered=stats.get("filtered", 0),
            errors=stats.get("errors", 0),
            total_processed=stats["total"],
            total_available=api_total,
            db_count=db_count,
            coverage_pct=round(coverage_pct, 1),
            elapsed_s=round(elapsed, 1),
            phases=list(self._phases),
            error_log=(stats.get("error_log") or [])[-50:],
            error_types=stats.get("error_types", {}),
            filter_rules=stats.get("filter_rules", {}),
            time_human=self.format_elapsed(elapsed),
            search_time_s=round(stats.get("search_time", 0.0), 1),
            enrich_time_s=round(stats.get("enrich_time", 0.0), 1),
            inspect_time_s=round(stats.get("inspect_time", 0.0), 1),
            pause_time_s=round(stats.get("pause_time", 0.0), 1),
            avg_per_lot_s=avg_per_lot,
        )
        self._phases = []  # reset for next run
        return result
