from .base import AbstractParser
from .kbcha import KBChaParser
from .registry import register

from config import Config

register(
    "kbcha",
    KBChaParser,
    enabled=Config.KBCHA_ENABLED,
    schedule=Config.KBCHA_SCHEDULE,
    interval_minutes=Config.KBCHA_INTERVAL_MINUTES,
)

__all__ = ["AbstractParser", "KBChaParser", "register"]
