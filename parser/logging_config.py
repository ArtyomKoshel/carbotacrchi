"""
Unified logging configuration for the parser process.

Provides:
- UTC3Formatter — timestamps in UTC+3
- setup_logging() — idempotent root logger setup (stdout + optional file)
- Suppression of noisy third-party loggers

Both main.py and job_worker.py import from here to avoid drift (P2, P6).
"""
from __future__ import annotations

import json
import logging
import os
import sys
from logging.handlers import RotatingFileHandler

from config import Config

_configured = False


class UTC3Formatter(logging.Formatter):
    """Logging formatter that stamps times in UTC+3."""
    _tz = __import__("datetime").timezone(__import__("datetime").timedelta(hours=3))

    def formatTime(self, record, datefmt=None):
        import datetime as _dt
        dt = _dt.datetime.fromtimestamp(record.created, tz=self._tz)
        return dt.strftime(datefmt or "%Y-%m-%d %H:%M:%S")


class JsonFormatter(UTC3Formatter):
    """JSON formatter for structured logs (LOG_FORMAT=json)."""

    def format(self, record: logging.LogRecord) -> str:
        payload = {
            "ts": self.formatTime(record, "%Y-%m-%d %H:%M:%S"),
            "level": record.levelname,
            "logger": record.name,
            "msg": record.getMessage(),
            "thread": record.threadName,
        }
        if record.exc_info:
            payload["exc"] = self.formatException(record.exc_info)
        if hasattr(record, "job_id"):
            payload["job_id"] = getattr(record, "job_id")
        return json.dumps(payload, ensure_ascii=False)


def _build_formatter() -> logging.Formatter:
    if Config.LOG_FORMAT == "json":
        return JsonFormatter()
    return UTC3Formatter(
        "%(asctime)s [%(levelname)s] %(name)s: %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )


LOG_FMT = _build_formatter()

# Loggers that should never be louder than WARNING
_NOISY_LOGGERS = (
    "httpcore", "httpcore.http11", "httpcore.connection", "httpcore.proxy",
    "httpx",
    "apscheduler.scheduler", "apscheduler.executors", "apscheduler.executors.default",
    "apscheduler.jobstores", "apscheduler.jobstores.default",
)


def setup_logging(debug: bool = False) -> None:
    """Configure root logger (idempotent — safe to call multiple times)."""
    global _configured
    if _configured:
        return
    _configured = True

    level = logging.DEBUG if debug else getattr(logging, Config.LOG_LEVEL, logging.INFO)

    root = logging.getLogger()
    root.setLevel(level)
    root.handlers.clear()

    ch = logging.StreamHandler(sys.stdout)
    ch.setFormatter(LOG_FMT)
    root.addHandler(ch)

    for name in _NOISY_LOGGERS:
        logging.getLogger(name).setLevel(logging.WARNING)

    if Config.LOG_FILE:
        os.makedirs(os.path.dirname(Config.LOG_FILE), exist_ok=True)
        fh = RotatingFileHandler(
            Config.LOG_FILE, maxBytes=20 * 1024 * 1024, backupCount=10, encoding="utf-8"
        )
        fh.setFormatter(LOG_FMT)
        root.addHandler(fh)
        try:
            os.chmod(Config.LOG_FILE, 0o666)
        except OSError:
            pass
