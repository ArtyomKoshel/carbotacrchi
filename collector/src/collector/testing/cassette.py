"""Replay of recorded Encar traffic into httpx (sync and async clients).

Reads the cassettes written by parser/tools/cassette.py (one JSON line per
response: key = method + URL with sorted query, status, content type, body).
Used by the golden-master parity check and to develop adapters offline:
Encar does not answer outside Korea.
"""

from __future__ import annotations

import gzip
import json
import threading
from collections.abc import Iterator
from contextlib import contextmanager
from pathlib import Path
from typing import Any
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit

import httpx


def request_key(method: str, url: str) -> str:
    parts = urlsplit(url)
    query = urlencode(sorted(parse_qsl(parts.query, keep_blank_values=True)))
    return f"{method.upper()} {urlunsplit((parts.scheme, parts.netloc, parts.path, query, ''))}"


class CassetteMissError(LookupError):
    """A request the cassette does not hold."""


class Cassette:
    def __init__(self, entries: dict[str, list[dict[str, Any]]] | None = None) -> None:
        self._entries = entries or {}
        self._cursor: dict[str, int] = {}
        self._lock = threading.Lock()
        self.misses: list[str] = []

    @classmethod
    def load(cls, path: str | Path) -> Cassette:
        entries: dict[str, list[dict[str, Any]]] = {}
        with gzip.open(path, "rt", encoding="utf-8") as fh:
            for line in fh:
                if line.strip():
                    entry = json.loads(line)
                    entries.setdefault(entry["key"], []).append(entry)
        return cls(entries)

    def __len__(self) -> int:
        return sum(len(v) for v in self._entries.values())

    def response_for(self, request: httpx.Request) -> httpx.Response:
        key = request_key(request.method, str(request.url))
        with self._lock:
            entries = self._entries.get(key)
            if not entries:
                self.misses.append(key)
                raise CassetteMissError(key)
            i = self._cursor.get(key, 0)
            self._cursor[key] = i + 1
            entry = entries[min(i, len(entries) - 1)]
        headers = {"content-type": entry["content_type"]} if entry["content_type"] else {}
        return httpx.Response(entry["status"], headers=headers, text=entry["body"], request=request)


@contextmanager
def replaying(cassette: Cassette) -> Iterator[Cassette]:
    """Every httpx request (sync or async) is answered from the cassette; nothing reaches the network."""
    sync_send, async_send = httpx.Client.send, httpx.AsyncClient.send

    def send(self: httpx.Client, request: httpx.Request, **_: Any) -> httpx.Response:
        return cassette.response_for(request)

    async def asend(self: httpx.AsyncClient, request: httpx.Request, **_: Any) -> httpx.Response:
        return cassette.response_for(request)

    httpx.Client.send = send  # type: ignore[method-assign]
    httpx.AsyncClient.send = asend  # type: ignore[method-assign]
    try:
        yield cassette
    finally:
        httpx.Client.send = sync_send  # type: ignore[method-assign]
        httpx.AsyncClient.send = async_send  # type: ignore[method-assign]
