"""Transactional local work queue and records; never connects to production DB."""

from __future__ import annotations

import json
import os
import sqlite3
from pathlib import Path
from typing import Any

from collector.adapters.che168.api import CollectionError, SearchPage
from collector.domain.carlot import CarLotV1


class FileStore:
    def __init__(self, directory: Path, *, fingerprint: str, resume: bool) -> None:
        directory.mkdir(parents=True, exist_ok=True)
        self.directory = directory
        # Separate OS-backed SQLite lock survives commits to state, but not process death.
        self.lock = sqlite3.connect(directory / "writer-lock.sqlite3", timeout=0)
        try:
            self.lock.execute("BEGIN EXCLUSIVE")
        except sqlite3.OperationalError:
            self.lock.close()
            raise CollectionError("output_in_use") from None
        self.db = sqlite3.connect(directory / "state.sqlite3")
        try:
            self._initialize(fingerprint, resume)
        except BaseException:
            self.close()
            raise

    def _initialize(self, fingerprint: str, resume: bool) -> None:
        self.db.executescript("""
            CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS pages (
                page INTEGER PRIMARY KEY, total INTEGER, page_count INTEGER, occurrences INTEGER
            );
            CREATE TABLE IF NOT EXISTS listings (id TEXT PRIMARY KEY, page INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS records (id TEXT PRIMARY KEY, payload TEXT NOT NULL);
        """)
        old = self.db.execute("SELECT value FROM meta WHERE key='fingerprint'").fetchone()
        if old:
            if not resume:
                raise CollectionError("output_exists_use_resume")
            if old[0] != fingerprint:
                raise CollectionError("resume_configuration_mismatch")
        elif resume:
            raise CollectionError("nothing_to_resume")
        else:
            with self.db:
                self.db.execute("INSERT INTO meta VALUES ('fingerprint', ?)", (fingerprint,))

    def close(self) -> None:
        self.db.close()
        self.lock.close()

    @property
    def next_page(self) -> int:
        row = self.db.execute("SELECT COALESCE(MAX(page), 0) + 1 FROM pages").fetchone()
        return int(row[0])

    @property
    def exhausted(self) -> bool:
        row = self.db.execute("SELECT page, page_count FROM pages ORDER BY page DESC LIMIT 1").fetchone()
        return row is not None and row[0] >= row[1]

    @property
    def written(self) -> int:
        return int(self.db.execute("SELECT COUNT(*) FROM records").fetchone()[0])

    def pending(self) -> str | None:
        row = self.db.execute(
            "SELECT l.id FROM listings l LEFT JOIN records r ON r.id=l.id "
            "WHERE r.id IS NULL ORDER BY l.page, l.rowid LIMIT 1"
        ).fetchone()
        return str(row[0]) if row else None

    def add_page(self, page: SearchPage) -> None:
        if page.number != self.next_page:
            raise CollectionError("checkpoint_page_mismatch")
        before = self.db.total_changes
        with self.db:
            self.db.executemany(
                "INSERT OR IGNORE INTO listings VALUES (?, ?)", ((infoid, page.number) for infoid in page.ids)
            )
            if page.total and self.db.total_changes == before:
                raise CollectionError("repeated_page_without_new_ids")
            self.db.execute(
                "INSERT INTO pages VALUES (?, ?, ?, ?)",
                (
                    page.number,
                    page.total,
                    page.page_count,
                    len(page.ids),
                ),
            )

    def save(self, lot: CarLotV1) -> None:
        with self.db:
            self.db.execute("INSERT INTO records VALUES (?, ?)", (lot.source_id, lot.model_dump_json()))

    def summary(self) -> dict[str, Any]:
        pages, occurrences = self.db.execute(
            "SELECT COUNT(*), COALESCE(SUM(occurrences), 0) FROM pages"
        ).fetchone()
        unique = self.db.execute("SELECT COUNT(*) FROM listings").fetchone()[0]
        return {
            "pages_saved": pages,
            "list_occurrences": occurrences,
            "unique_ids": unique,
            "records_written": self.written,
            "pending_details": unique - self.written,
            "next_page": self.next_page,
            "api_end_reached": self.exhausted,
            "full_run": False,
        }

    def export(self, summary: dict[str, Any]) -> None:
        target = self.directory / "cars.jsonl"
        temporary = self.directory / "cars.jsonl.tmp"
        with temporary.open("w", encoding="utf-8", newline="\n") as out:
            for row in self.db.execute("SELECT payload FROM records ORDER BY rowid"):
                out.write(row[0] + "\n")
            out.flush()
            os.fsync(out.fileno())
        temporary.replace(target)
        temporary = self.directory / "summary.json.tmp"
        temporary.write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
        temporary.replace(self.directory / "summary.json")
