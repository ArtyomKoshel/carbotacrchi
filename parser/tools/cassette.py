"""Record / replay of Encar HTTP traffic at the httpx level.

Used to pin what the current parser produces (golden master, refactor stage 0)
and to replay exactly the same responses into the new collector later.

Only requests to Encar hosts are stored. Request headers are never stored (they
can carry API keys); proxy credentials live in the transport and never reach
`Client.send`. Each entry is one JSON line:

    {"key": "GET https://api.encar.com/...?a=1&b=2", "status": 200,
     "content_type": "application/json", "body": "..."}
"""
from __future__ import annotations

import gzip
import json
import threading
from contextlib import contextmanager
from pathlib import Path
from typing import Iterator
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit

import httpx

RECORDED_HOST_SUFFIX = "encar.com"


def request_key(method: str, url: str) -> str:
    """Stable key: method + URL with query parameters sorted."""
    parts = urlsplit(url)
    query = urlencode(sorted(parse_qsl(parts.query, keep_blank_values=True)))
    return f"{method.upper()} {urlunsplit((parts.scheme, parts.netloc, parts.path, query, ''))}"


def is_recorded_host(url: str) -> bool:
    host = urlsplit(url).hostname or ""
    return host == RECORDED_HOST_SUFFIX or host.endswith("." + RECORDED_HOST_SUFFIX)


class CassetteMiss(LookupError):
    """Replay got a request that the cassette does not hold."""


class Cassette:
    def __init__(self) -> None:
        self._entries: dict[str, list[dict]] = {}
        self._cursor: dict[str, int] = {}
        self._lock = threading.Lock()

    # ── storage ──────────────────────────────────────────────────────────
    @classmethod
    def load(cls, path: str | Path) -> "Cassette":
        cassette = cls()
        with gzip.open(path, "rt", encoding="utf-8") as fh:
            for line in fh:
                if line.strip():
                    entry = json.loads(line)
                    cassette._entries.setdefault(entry["key"], []).append(entry)
        return cassette

    def save(self, path: str | Path) -> int:
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        n = 0
        with gzip.open(path, "wt", encoding="utf-8") as fh:
            for key in sorted(self._entries):
                for entry in self._entries[key]:
                    fh.write(json.dumps(entry, ensure_ascii=False) + "\n")
                    n += 1
        return n

    def __len__(self) -> int:
        return sum(len(v) for v in self._entries.values())

    # ── record / replay ──────────────────────────────────────────────────
    def add(self, request: httpx.Request, response: httpx.Response) -> None:
        entry = {
            "key": request_key(request.method, str(request.url)),
            "status": response.status_code,
            "content_type": response.headers.get("content-type", ""),
            "body": response.text,
        }
        with self._lock:
            self._entries.setdefault(entry["key"], []).append(entry)

    def response_for(self, request: httpx.Request) -> httpx.Response:
        key = request_key(request.method, str(request.url))
        with self._lock:
            entries = self._entries.get(key)
            if not entries:
                raise CassetteMiss(key)
            # Repeated identical requests replay in the order they were recorded,
            # then stick to the last one.
            i = self._cursor.get(key, 0)
            self._cursor[key] = i + 1
            entry = entries[min(i, len(entries) - 1)]
        headers = {"content-type": entry["content_type"]} if entry["content_type"] else {}
        return httpx.Response(entry["status"], headers=headers, text=entry["body"], request=request)


@contextmanager
def recording(cassette: Cassette) -> Iterator[Cassette]:
    """Every Encar response that passes through httpx.Client.send is stored."""
    original = httpx.Client.send

    def send(self: httpx.Client, request: httpx.Request, **kwargs):  # type: ignore[no-untyped-def]
        response = original(self, request, **kwargs)
        if is_recorded_host(str(request.url)):
            response.read()
            cassette.add(request, response)
        return response

    httpx.Client.send = send  # type: ignore[method-assign]
    try:
        yield cassette
    finally:
        httpx.Client.send = original  # type: ignore[method-assign]


@contextmanager
def replaying(cassette: Cassette) -> Iterator[Cassette]:
    """No network: Encar requests come from the cassette, anything else fails."""
    original = httpx.Client.send

    def send(self: httpx.Client, request: httpx.Request, **kwargs):  # type: ignore[no-untyped-def]
        if not is_recorded_host(str(request.url)):
            raise CassetteMiss(f"non-Encar request during replay: {request.method} {request.url}")
        return cassette.response_for(request)

    httpx.Client.send = send  # type: ignore[method-assign]
    try:
        yield cassette
    finally:
        httpx.Client.send = original  # type: ignore[method-assign]
