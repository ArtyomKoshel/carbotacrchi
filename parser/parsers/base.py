from __future__ import annotations

from abc import ABC, abstractmethod

from repository import LotRepository


class AbstractParser(ABC):
    def __init__(self, repo: LotRepository):
        self.repo = repo

    @abstractmethod
    def get_source_key(self) -> str:
        """Unique key: 'kbcha', 'encar', 'copart'"""

    @abstractmethod
    def get_source_name(self) -> str:
        """Display name: 'KBChacha', 'Encar', 'Copart'"""

    @abstractmethod
    def run(self) -> int:
        """Run full import cycle. Return total lots upserted."""
