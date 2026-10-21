"""Shadow mode (docs/adr/0007): the old worker and scheduler never see jobs tagged for the collector."""
from __future__ import annotations

import json
import os
from pathlib import Path

import pytest

import job_worker

URL = dict(
    host=os.environ.get("PARSER_TEST_DB_HOST", "127.0.0.1"),
    port=int(os.environ.get("PARSER_TEST_DB_PORT", "3318")),
    user=os.environ.get("PARSER_TEST_DB_USER", "carbot"),
    password=os.environ.get("PARSER_TEST_DB_PASSWORD", "carbot"),
    database=os.environ.get("PARSER_TEST_DB_NAME", "collector_test"),
)


@pytest.fixture
def conn():
    pymysql = pytest.importorskip("pymysql")
    try:
        c = pymysql.connect(**URL, cursorclass=pymysql.cursors.DictCursor, autocommit=True)
    except Exception as e:  # noqa: BLE001
        pytest.skip(f"MySQL not reachable: {type(e).__name__}")
    with c.cursor() as cur:
        cur.execute("DROP TABLE IF EXISTS parse_jobs_shadow_test")
        cur.execute("CREATE TABLE parse_jobs_shadow_test (id INT PRIMARY KEY, filters JSON NULL)")
    yield c
    with c.cursor() as cur:
        cur.execute("DROP TABLE parse_jobs_shadow_test")
    c.close()


def test_collector_tagged_jobs_are_filtered_out(conn):
    rows = [(1, None), (2, json.dumps({})), (3, json.dumps({"max_pages": 5})),
            (4, json.dumps({"engine": "collector"})), (5, json.dumps({"engine": "parser"}))]
    with conn.cursor() as cur:
        cur.executemany("INSERT INTO parse_jobs_shadow_test VALUES (%s, %s)", rows)
        cur.execute(f"SELECT id FROM parse_jobs_shadow_test WHERE 1=1 {job_worker.NOT_COLLECTOR_JOB} ORDER BY id")
        assert [r["id"] for r in cur.fetchall()] == [1, 2, 3, 5]


def test_scheduler_uses_the_same_rule():
    source = (Path(__file__).resolve().parents[1] / "scheduler.py").read_text(encoding="utf-8")
    assert "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(filters, '$.engine')), 'parser') <> 'collector'" in source
