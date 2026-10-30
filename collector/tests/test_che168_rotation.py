from __future__ import annotations

from pathlib import Path

import pytest

from collector.adapters.che168.api import DETAIL_URL, Che168Api, CollectionError, Reply, RotationPolicy
from collector.adapters.che168.pipeline import run
from collector.adapters.che168.signing import Signer
from collector.adapters.che168.store import FileStore
from tests.test_che168 import detail, reply


def test_periodic_rotation_follows_completed_pages(tmp_path: Path) -> None:
    events: list[str] = []

    def request(url: str, params: dict[str, str]) -> Reply:
        if url == DETAIL_URL:
            events.append("detail_" + params["infoid"])
            return reply(detail(params["infoid"]))
        page = int(params["pageindex"])
        events.append(f"page_{page}")
        return reply(
            {
                "pageindex": page,
                "pagesize": 20,
                "pagecount": 5,
                "totalcount": 5,
                "carlist": [{"infoid": page}],
            }
        )

    api = Che168Api(request, Signer.bundled(), delay=0, rotate=lambda: events.append("rotate"))
    store = FileStore(tmp_path, fingerprint="c", resume=False)
    result = run(api, store, max_pages=5, max_cars=10, pagesize=20)
    assert events == [
        "page_1",
        "detail_1",
        "page_2",
        "detail_2",
        "rotate",
        "page_3",
        "detail_3",
        "page_4",
        "detail_4",
        "rotate",
        "page_5",
        "detail_5",
    ]
    assert result["proxy_rotations_this_invocation"] == 2
    assert result["challenge_rotations_this_invocation"] == 0
    store.close()


def test_challenge_retries_same_id_with_new_session() -> None:
    events: list[str] = []

    def request(url: str, params: dict[str, str]) -> Reply:
        events.append(params["infoid"])
        return Reply(200, b"captcha.aspx") if len(events) == 1 else reply(detail("9"))

    api = Che168Api(request, Signer.bundled(), delay=0, rotate=lambda: events.append("rotate"))
    assert api.detail("9")["infoid"] == 9
    assert events == ["9", "rotate", "9"]
    assert api.challenge_rotations == 1


def test_persistent_challenge_stops_at_budget() -> None:
    rotations = []
    api = Che168Api(
        lambda u, p: Reply(200, b"Security Verification"),
        Signer.bundled(),
        delay=0,
        rotate=lambda: rotations.append(1),
    )
    with pytest.raises(CollectionError, match="challenge_rotation_limit"):
        api.detail("1")
    assert api.requests == 4
    assert len(rotations) == 3


@pytest.mark.parametrize(
    ("response", "reason"),
    [
        (reply({}, code=107), "signature_rejected"),
        (Reply(407, b""), "authentication_error"),
        (Reply(429, b""), "rate_limited"),
        (Reply(200, b"broken response"), "invalid_json"),
    ],
)
def test_non_proxy_failures_do_not_rotate(response: Reply, reason: str) -> None:
    rotations = []
    api = Che168Api(lambda u, p: response, Signer.bundled(), delay=0, rotate=lambda: rotations.append(1))
    with pytest.raises(CollectionError, match=reason):
        api.detail("1")
    assert rotations == []


def test_total_rotation_budget() -> None:
    api = Che168Api(
        lambda u, p: Reply(403, b""),
        Signer.bundled(),
        delay=0,
        rotate=lambda: None,
        rotation=RotationPolicy(max_rotations=1),
    )
    with pytest.raises(CollectionError, match="proxy_rotation_limit"):
        api.detail("1")
    assert api.requests == 2
