"""Sample run: N lots per (maker, model group), the same requests as the old parser's run_sample().

Used for the golden-master parity check (replayed traffic) and for quick
integration checks after a change: a small, representative set of lots.
"""

from __future__ import annotations

from typing import Any

from collector.adapters.encar.api import DEFAULT_QUERY, PAGE_SIZE, EncarApi, maker_query
from collector.adapters.encar.pipeline import EncarPipeline, PageReport
from collector.http import Ok


async def discover_model_groups(api: EncarApi) -> dict[str, set[str]]:
    """Makers and model groups seen on the first search page (as the old parser did)."""
    first = await api.search(DEFAULT_QUERY, 0, PAGE_SIZE)
    pairs: dict[str, set[str]] = {}
    if not isinstance(first, Ok):
        return pairs
    for item in first.data.get("SearchResults") or []:
        maker = (item.get("Manufacturer") or "").strip()
        group = (item.get("ModelGroup") or "").strip()
        if maker:
            pairs.setdefault(maker, set())
            if group:
                pairs[maker].add(group)
    return pairs


async def run_sample(pipeline: EncarPipeline, *, per_model: int, run_id: int | None = None) -> PageReport:
    api = pipeline.api
    report = PageReport()
    seen: set[str] = set()
    for maker, groups in sorted((await discover_model_groups(api)).items()):
        for group in sorted(groups) or [""]:
            outcome = await api.search(maker_query(maker, group or None), 0, per_model)
            if not isinstance(outcome, Ok):
                continue
            items: list[dict[str, Any]] = []
            for it in (outcome.data.get("SearchResults") or [])[:per_model]:
                vid = str(it.get("Id", ""))
                if vid and vid not in seen:
                    seen.add(vid)
                    items.append(it)
            if items:
                report = report.plus(await pipeline.process(items, run_id=run_id))
    return report
