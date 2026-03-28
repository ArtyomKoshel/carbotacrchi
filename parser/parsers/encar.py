"""Encar parser — stub for future implementation.

Requires Korean proxy/VPN for full filter support.
API: https://api.encar.com/search/car/list/premium
"""

import logging

from .base import AbstractParser

logger = logging.getLogger(__name__)


class EncarParser(AbstractParser):
    def get_source_key(self) -> str:
        return "encar"

    def get_source_name(self) -> str:
        return "Encar"

    def fetch_listings(self, page: int) -> list[dict]:
        raise NotImplementedError("Encar parser not yet implemented")

    def normalize(self, raw: dict) -> dict | None:
        raise NotImplementedError("Encar parser not yet implemented")
