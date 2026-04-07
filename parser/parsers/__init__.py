from .base import AbstractParser
from .kbcha import KBChaParser
from .encar import EncarParser
from .registry import register

from config import Config

register(
    "kbcha",
    KBChaParser,
    enabled=Config.KBCHA_ENABLED,
    schedule=Config.KBCHA_SCHEDULE,
    interval_minutes=Config.KBCHA_INTERVAL_MINUTES,
)

register(
    "encar",
    EncarParser,
    enabled=Config.ENCAR_ENABLED,
    schedule=getattr(Config, "ENCAR_SCHEDULE", ""),
    interval_minutes=Config.ENCAR_INTERVAL_MINUTES,
)

__all__ = ["AbstractParser", "KBChaParser", "EncarParser", "register"]
