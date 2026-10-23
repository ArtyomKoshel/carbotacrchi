"""Collector settings from the environment (12-factor). No secret is logged."""

from __future__ import annotations

import os
from dataclasses import dataclass, field


def _env(name: str, default: str = "") -> str:
    return os.environ.get(name, default).strip()


def _int(name: str, default: int) -> int:
    raw = _env(name)
    return int(raw) if raw else default


@dataclass(frozen=True)
class Settings:
    database_url: str = field(repr=False)  # collector schema, e.g. mysql+aiomysql://user:pw@host/collector
    core_schema: str = "railway"  # where parse_jobs and parse_filters live
    sources: tuple[str, ...] = ("encar",)
    poll_seconds: int = 15
    concurrency: int = 25  # requests in flight; the old parser ran ENCAR_WORKERS=15 threads
    history_concurrency: int = 25
    pipeline_depth: int = 3  # pages in flight: the next one loads while earlier histories finish
    # A stored history older than this is fetched again, besides the oldest 1/history_every_runs
    # (audit 23.10.2026: no real change missed under 3 days, ~1% at 3-7 days). 0 = by runs only.
    history_max_age_hours: int = 48
    # Encar answers in under 2 s; a request still open after this is a stuck proxy exit, and the
    # whole page waits for it. It is retried on a fresh proxy session instead.
    http_timeout: float = 12.0
    history_every_runs: int = 3
    min_coverage_pct: float = 85.0
    proxy_username: str = field(default="", repr=False)
    proxy_password: str = field(default="", repr=False)
    proxy_host: str = ""
    proxy_sessions: int = 30
    stop_grace_seconds: float = 25.0  # after SIGTERM, how long the current page may run on
    stale_minutes: int = 10  # a running job without progress this long was left by a killed collector

    @classmethod
    def from_env(cls) -> Settings:
        return cls(
            database_url=_env("COLLECTOR_DATABASE_URL"),
            core_schema=_env("COLLECTOR_CORE_SCHEMA", "railway"),
            sources=tuple(s for s in _env("COLLECTOR_SOURCES", "encar").split(",") if s),
            poll_seconds=_int("COLLECTOR_POLL_SECONDS", 15),
            concurrency=_int("COLLECTOR_CONCURRENCY", 25),
            history_concurrency=_int("COLLECTOR_HISTORY_CONCURRENCY", 25),
            pipeline_depth=_int("COLLECTOR_PIPELINE_DEPTH", 3),
            history_max_age_hours=_int("COLLECTOR_HISTORY_MAX_AGE_HOURS", 48),
            http_timeout=float(_env("COLLECTOR_HTTP_TIMEOUT", "12")),
            history_every_runs=_int("ENCAR_HISTORY_EVERY_RUNS", 3),
            min_coverage_pct=float(_env("LOTS_LIFECYCLE_MIN_COVERAGE", "85")),
            proxy_username=_env("FLOPPY_USERNAME"),
            proxy_password=_env("FLOPPY_PASSWORD"),
            proxy_host=_env("FLOPPY_HOST"),
            proxy_sessions=_int("COLLECTOR_PROXY_SESSIONS", 30),
            stop_grace_seconds=float(_env("COLLECTOR_STOP_GRACE_SECONDS", "25")),
            stale_minutes=_int("COLLECTOR_STALE_MINUTES", 10),
        )

    @property
    def has_proxy(self) -> bool:
        return bool(self.proxy_username and self.proxy_password and self.proxy_host)
