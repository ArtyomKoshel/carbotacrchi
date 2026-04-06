from __future__ import annotations

from dataclasses import dataclass
from typing import Type

from parsers.base import AbstractParser


@dataclass
class ParserRegistration:
    cls: Type[AbstractParser]
    enabled: bool
    schedule: str           # "" | "interval:60" | "cron:0 * * * *"
    interval_minutes: int   # fallback when schedule is empty


_REGISTRY: dict[str, ParserRegistration] = {}


def register(
    source_key: str,
    cls: Type[AbstractParser],
    enabled: bool,
    schedule: str = "",
    interval_minutes: int = 60,
) -> None:
    _REGISTRY[source_key] = ParserRegistration(
        cls=cls,
        enabled=enabled,
        schedule=schedule,
        interval_minutes=interval_minutes,
    )


def get_all() -> dict[str, ParserRegistration]:
    return dict(_REGISTRY)


def get_enabled() -> dict[str, ParserRegistration]:
    return {k: v for k, v in _REGISTRY.items() if v.enabled}
