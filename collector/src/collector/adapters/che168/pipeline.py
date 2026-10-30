"""Drain saved work before advancing pagination, with resumable bounded runs."""

from __future__ import annotations

from collections.abc import Callable
from typing import Any

from collector.adapters.che168.api import Che168Api, CollectionError
from collector.adapters.che168.mapping import to_lot
from collector.adapters.che168.store import FileStore
from collector.ports import Clock, utc_now


def collect(
    api: Che168Api,
    store: FileStore,
    *,
    max_pages: int,
    max_cars: int,
    pagesize: int,
    stopped: Callable[[], bool] = lambda: False,
    clock: Clock = utc_now,
    progress: Callable[[dict[str, Any]], None] = lambda event: None,
) -> str:
    if max_pages < store.next_page - 1 or max_cars < store.written:
        raise CollectionError("limits_below_saved_progress")
    while True:
        if stopped():
            return "interrupted"
        if store.written >= max_cars:
            return "car_limit"
        infoid = store.pending()
        if infoid:
            lot = to_lot(api.detail(infoid), clock())
            store.save(lot)
            progress({"event": "detail", "source_id": infoid, "records_written": store.written})
            continue
        if store.exhausted:
            return "api_end_reached"
        if store.next_page > max_pages:
            return "page_limit"
        page = api.search(store.next_page, pagesize)
        store.add_page(page)
        progress({"event": "page", "page": page.number, "count": len(page.ids), **store.summary()})


def run(
    api: Che168Api,
    store: FileStore,
    *,
    max_pages: int,
    max_cars: int,
    pagesize: int,
    stopped: Callable[[], bool] = lambda: False,
    progress: Callable[[dict[str, Any]], None] = lambda event: None,
) -> dict[str, Any]:
    try:
        status = collect(
            api,
            store,
            max_pages=max_pages,
            max_cars=max_cars,
            pagesize=pagesize,
            stopped=stopped,
            progress=progress,
        )
    except CollectionError as exc:
        status = exc.kind
    except KeyboardInterrupt:
        status = "interrupted"
    summary = {
        "source": "che168",
        "method": "api",
        "status": status,
        **store.summary(),
        "requests_this_invocation": api.requests,
        "proxy_rotations_this_invocation": api.rotations,
        "challenge_rotations_this_invocation": api.challenge_rotations,
        "last_request_error": api.last_error,
        "response_body_bytes_this_invocation": api.response_bytes,
        "observed_at": utc_now().isoformat(),
        "bundle_sha256": api.signer.bundle_sha256,
    }
    store.export(summary)
    return summary
