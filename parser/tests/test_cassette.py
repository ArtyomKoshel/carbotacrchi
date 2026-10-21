"""tools.cassette: Encar traffic is stored without headers and replayed offline."""
from __future__ import annotations

import gzip
import json

import httpx
import pytest

from tools.cassette import Cassette, CassetteMiss, is_recorded_host, recording, replaying, request_key


def _encar_transport(counter: dict) -> httpx.MockTransport:
    def handler(request: httpx.Request) -> httpx.Response:
        counter["n"] = counter.get("n", 0) + 1
        return httpx.Response(200, json={"echo": request.url.params.get("q"), "n": counter["n"]})
    return httpx.MockTransport(handler)


def test_request_key_sorts_query():
    assert request_key("get", "https://api.encar.com/x?b=2&a=1") == request_key("GET", "https://api.encar.com/x?a=1&b=2")


@pytest.mark.parametrize("url,ok", [
    ("https://api.encar.com/search", True),
    ("https://encar.com/", True),
    ("https://fem.encar.com/cars", True),
    ("https://api.floppydata.com/v1/rotating/balance", False),
    ("https://notencar.com/", False),
])
def test_only_encar_hosts_are_recorded(url, ok):
    assert is_recorded_host(url) is ok


def test_record_then_replay_without_network(tmp_path):
    counter: dict = {}
    cassette = Cassette()
    with recording(cassette):
        with httpx.Client(transport=_encar_transport(counter)) as c:
            first = c.get("https://api.encar.com/search", params={"q": "A"}, headers={"X-Api-Key": "secret"}).json()
            c.get("https://api.encar.com/search", params={"q": "A"})  # repeated request
    path = tmp_path / "c.jsonl.gz"
    assert cassette.save(path) == 2

    raw = gzip.open(path, "rt", encoding="utf-8").read()
    assert "secret" not in raw and "X-Api-Key" not in raw

    loaded = Cassette.load(path)

    def boom(request: httpx.Request) -> httpx.Response:  # network must not be used
        raise AssertionError("network used during replay")

    with replaying(loaded):
        with httpx.Client(transport=httpx.MockTransport(boom)) as c:
            a = c.get("https://api.encar.com/search", params={"q": "A"}).json()
            b = c.get("https://api.encar.com/search", params={"q": "A"}).json()
            c3 = c.get("https://api.encar.com/search", params={"q": "A"}).json()
    assert a == first and b["n"] == 2 and c3["n"] == 2  # recorded order, then the last one


def test_replay_miss_and_foreign_host_fail():
    with replaying(Cassette()):
        with httpx.Client() as c:
            with pytest.raises(CassetteMiss):
                c.get("https://api.encar.com/unknown")
            with pytest.raises(CassetteMiss):
                c.get("https://example.org/")


def test_patch_is_removed_after_context():
    original = httpx.Client.send
    with replaying(Cassette()):
        assert httpx.Client.send is not original
    assert httpx.Client.send is original
