"""Ports of the collector (hexagonal architecture): what the pipeline needs from the outside.

Adapters: MySQL implementations (collector.storage) in production, in-memory ones
(below) in tests and for replaying recorded traffic.
"""

from __future__ import annotations

from collections.abc import Callable, Iterable, Sequence
from dataclasses import dataclass, field
from datetime import UTC, datetime
from typing import Any, Protocol

from collector.domain.carlot import CarLotV1

Clock = Callable[[], datetime]


def utc_now() -> datetime:
    return datetime.now(UTC).replace(microsecond=0)


@dataclass(frozen=True, slots=True)
class HistoryState:
    """What the collector last fetched about a lot's history (record + inspection)."""

    lot_id: str
    checked_at: datetime
    condition: tuple[str, ...] = ()
    fields: dict[str, Any] = field(default_factory=dict)


class HistoryStore(Protocol):
    async def states(self, source: str, lot_ids: Sequence[str]) -> dict[str, HistoryState]: ...

    async def save(self, source: str, states: Iterable[HistoryState]) -> None: ...


class IngestSink(Protocol):
    async def write(self, lots: Sequence[CarLotV1]) -> None: ...


class MemoryHistoryStore:
    def __init__(self) -> None:
        self.data: dict[tuple[str, str], HistoryState] = {}

    async def states(self, source: str, lot_ids: Sequence[str]) -> dict[str, HistoryState]:
        return {i: self.data[(source, i)] for i in lot_ids if (source, i) in self.data}

    async def save(self, source: str, states: Iterable[HistoryState]) -> None:
        for s in states:
            self.data[(source, s.lot_id)] = s


class MemorySink:
    def __init__(self) -> None:
        self.lots: list[CarLotV1] = []

    async def write(self, lots: Sequence[CarLotV1]) -> None:
        self.lots.extend(lots)

    def by_id(self) -> dict[str, CarLotV1]:
        return {lot.source_id: lot for lot in self.lots}
