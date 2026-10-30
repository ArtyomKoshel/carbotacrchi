from __future__ import annotations

import json
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

import pytest

from collector.adapters.che168.api import (
    DETAIL_URL,
    Che168Api,
    CollectionError,
    Reply,
    SearchPage,
    decode_reply,
    identifier,
    proxy_url,
)
from collector.adapters.che168.mapping import (
    body_type,
    listed_at,
    model_year,
    optional_engine,
    registration,
    retail_value,
    scaled_integer,
    to_lot,
)
from collector.adapters.che168.pipeline import collect, run
from collector.adapters.che168.signing import Signer
from collector.adapters.che168.store import FileStore
from collector.domain.carlot import BodyType, DriveType, Fuel

NOW = datetime(2026, 10, 29, tzinfo=UTC)


def detail(infoid: str = "1", **changes: Any) -> dict[str, Any]:
    return {
        "infoid": int(infoid),
        "dealerid": 100,
        "brandname": "日产",
        "seriesname": "逍客",
        "carname": "逍客 2023款 1.3T CVT豪华版",
        "price": "12.68",
        "mileage": "0.8",
        "displacement": "1.3",
        "firstregdate": "2025-07",
        "gearbox": "自动",
        "drivingmode": "前置前驱",
        "fuelname": "汽油",
        "setcount": 5,
        "piclist": ["https://example.com/car.jpg", "https://example.com/car.jpg"],
        "vincode": "DO NOT STORE",
        "phone": "DO NOT STORE",
        "remark": "DO NOT STORE",
        "transfercount": 1,
        **changes,
    }


def reply(result: Any, *, code: int = 0, status: int = 200) -> Reply:
    return Reply(status, json.dumps({"returncode": code, "result": result}).encode())


class Fake:
    def __init__(self) -> None:
        self.calls: list[tuple[str, dict[str, str]]] = []
        self.fail_id: str | None = None

    def request(self, url: str, params: dict[str, str]) -> Reply:
        self.calls.append((url, params))
        if url == DETAIL_URL:
            if params["infoid"] == self.fail_id:
                return Reply(200, b"<title>Security Verification</title>")
            return reply(detail(params["infoid"]))
        page = int(params["pageindex"])
        ids = [1, 2] if page == 1 else [2, 3]
        return reply(
            {
                "pageindex": page,
                "pagesize": 20,
                "pagecount": 2,
                "totalcount": 3,
                "carlist": [{"infoid": i} for i in ids],
            }
        )


def client(fake: Fake, **kwargs: Any) -> Che168Api:
    return Che168Api(fake.request, Signer.bundled(), delay=0, **kwargs)


def test_mapping_units_missing_precision_and_privacy() -> None:
    lot = to_lot(detail(), NOW)
    assert lot.listing.price.amount == 126800
    assert lot.listing.price.currency == "CNY"
    assert lot.listing.mileage_km == 8000
    assert lot.vehicle.year == 2023  # the «2023款» model year, not the 2025 registration
    assert lot.vehicle.first_registration is None
    assert lot.extra["che168"]["firstregdate"] == "2025-07"
    assert lot.history.owners_count is None  # transfers are not owners
    assert lot.vehicle.engine_volume_l == 1.3
    assert len(lot.listing.photos) == 1
    assert "DO NOT STORE" not in lot.model_dump_json()
    assert lot.extra["selection_evaluated"] is False


@pytest.mark.parametrize("value", [None, "", -1, "NaN", "Infinity", True, "0.00001", "price"])
def test_invalid_amounts_never_become_zero(value: Any) -> None:
    with pytest.raises(CollectionError, match="invalid_price_or_mileage"):
        scaled_integer(value)


def test_zero_and_unknown_optional_fields() -> None:
    lot = to_lot(
        detail(
            price=0,
            mileage=0,
            fuelname="unknown",
            drivingmode="四驱",
            displacement="纯电动",
            setcount=0,
            firstregdate="2020-01-12",
            piclist=["//example.com/photo.jpg"],
        ),
        NOW,
    )
    assert lot.listing.price.amount == lot.listing.mileage_km == 0
    assert lot.vehicle.fuel is None
    assert lot.vehicle.drive_type is None
    assert lot.vehicle.engine_volume_l is None
    assert lot.vehicle.seat_count is None
    assert str(lot.vehicle.first_registration) == "2020-01-12"
    assert optional_engine("100") is None
    assert registration(None) is None


@pytest.mark.parametrize(
    ("fuelname", "fuel"),
    [
        ("插电混动", Fuel.PLUGIN_HYBRID),
        ("增程式", Fuel.PLUGIN_HYBRID),
        ("汽油+48V轻混系统", Fuel.GASOLINE),
        ("油电混合", Fuel.HYBRID),
        ("", None),
    ],
)
def test_fuel_vocabulary(fuelname: str, fuel: Fuel | None) -> None:
    assert to_lot(detail(fuelname=fuelname), NOW).vehicle.fuel == fuel


@pytest.mark.parametrize("mode", ["前置四驱", "双电机四驱", "四电机四驱"])
def test_four_wheel_drive_is_awd(mode: str) -> None:
    assert to_lot(detail(drivingmode=mode), NOW).vehicle.drive_type == DriveType.AWD


def test_body_color_location_and_listing_date() -> None:
    lot = to_lot(
        detail(levelname="中大型SUV", colorname="银/灰色", cname="上海", publicdate="2天前"),
        datetime(2026, 10, 29, 20, tzinfo=UTC),  # already 30 October in China
    )
    assert lot.vehicle.body_type == BodyType.SUV
    assert lot.vehicle.color == "Silver/Gray"
    assert lot.listing.location == "上海"
    assert lot.listing.listed_at is not None
    assert lot.listing.listed_at.isoformat() == "2026-10-28T00:00:00+08:00"
    assert lot.extra["che168"]["cname"] == "上海"
    unknown = to_lot(detail(colorname="珍珠白", levelname="中型车", cname=" "), NOW)
    assert unknown.vehicle.color == "珍珠白"
    assert unknown.vehicle.body_type is None
    assert unknown.listing.location is None


@pytest.mark.parametrize(
    ("value", "expected"),
    [
        ("今天", "2026-10-29"),
        ("昨天", "2026-10-28"),
        ("2026-10-14", "2026-10-14"),
        ("刚刚", None),
        (None, None),
    ],
)
def test_listing_date_forms(value: Any, expected: str | None) -> None:
    got = listed_at(value, NOW)
    assert (got.date().isoformat() if got else None) == expected


def test_retail_value_from_guidance_price() -> None:
    lot = to_lot(detail(guidanceprice="54.5"), NOW)
    assert lot.listing.retail_value is not None
    assert (lot.listing.retail_value.amount, lot.listing.retail_value.currency) == (545000, "CNY")
    assert retail_value(0) is None
    assert retail_value("") is None
    assert to_lot(detail(), NOW).listing.retail_value is None


def test_model_year_and_body_only_when_stated() -> None:
    assert model_year("Macan 2018款 Macan 2.0T") == 2018
    assert model_year("Model 3 后轮驱动版") is None
    assert model_year("20180款") is None
    assert model_year(None) is None
    assert body_type("中大型MPV") == BodyType.MINIVAN
    assert body_type("跑车") == BodyType.COUPE
    assert body_type("低端皮卡") == BodyType.PICKUP
    assert body_type(None) is None


@pytest.mark.parametrize(
    ("changes", "reason"),
    [
        ({"piclist": [{}]}, "photo_schema_changed"),
        ({"piclist": None}, "photo_schema_changed"),
        ({"piclist": ["bad url"]}, "invalid_vehicle_fields"),
        ({"brandname": None}, "invalid_vehicle_fields"),
        ({"mileage": 999}, "invalid_vehicle_fields"),
    ],
)
def test_mapping_rejects_unusable_records(changes: dict[str, Any], reason: str) -> None:
    with pytest.raises(CollectionError, match=reason):
        to_lot(detail(**changes), NOW)


@pytest.mark.parametrize(
    ("response", "reason"),
    [
        (Reply(200, b"<script>CAPTCHA</script>"), "challenge"),
        (Reply(302, b""), "access_blocked"),
        (Reply(429, b""), "rate_limited"),
        (Reply(402, b""), "proxy_budget_exhausted"),
        (Reply(404, b""), "http_error"),
        (reply({}, code=107), "signature_rejected"),
        (reply({}, code=9), "api_rejected"),
        (reply([]), "api_schema_changed"),
        (Reply(200, b"[]"), "api_schema_changed"),
    ],
)
def test_explicit_failure_classification(response: Reply, reason: str) -> None:
    with pytest.raises(CollectionError, match=reason):
        decode_reply(response)


def test_no_retry_on_captcha_and_retry_on_transport_failure() -> None:
    responses: list[CollectionError | Reply] = [CollectionError("network_error"), reply(detail())]

    def request(url: str, params: dict[str, str]) -> Reply:
        response = responses.pop(0)
        if isinstance(response, CollectionError):
            raise response
        return response

    api = Che168Api(request, Signer.bundled(), delay=0)
    assert api.detail("1")["infoid"] == 1
    assert api.requests == 2
    fake = Fake()
    fake.fail_id = "1"
    api = client(fake)
    with pytest.raises(CollectionError, match="challenge"):
        api.detail("1")
    assert api.requests == 1


def test_budgets_and_rate_limit() -> None:
    instant = [0.0]
    sleeps = []

    def sleep(seconds: float) -> None:
        sleeps.append(seconds)
        instant[0] += seconds

    api = Che168Api(
        Fake().request,
        Signer.bundled(),
        delay=3,
        clock=lambda: instant[0],
        sleep=sleep,
        max_seconds=5,
        max_requests=10,
    )
    api.detail("1")
    api.detail("2")
    assert sleeps == [0, 3]
    with pytest.raises(CollectionError, match="time_limit"):
        api.detail("3")
    api = client(Fake(), max_requests=1)
    api.detail("1")
    with pytest.raises(CollectionError, match="request_limit"):
        api.detail("2")


@pytest.mark.parametrize(
    ("change", "reason"),
    [
        ({"pageindex": 2}, "pagination_mismatch"),
        ({"pagesize": 10}, "pagination_mismatch"),
        ({"carlist": []}, "incomplete_search_page"),
        ({"pagecount": 0}, "incomplete_search_page"),
        ({"totalcount": "20"}, "search_schema_changed"),
        ({"carlist": [None]}, "search_schema_changed"),
        ({"totalcount": 0}, "inconsistent_search_total"),
    ],
)
def test_pagination_validation(change: dict[str, Any], reason: str) -> None:
    data = {
        "pageindex": 1,
        "pagesize": 20,
        "pagecount": 2,
        "totalcount": 21,
        "carlist": [{"infoid": 1}],
        **change,
    }
    api = Che168Api(lambda u, p: reply(data), Signer.bundled(), delay=0)
    with pytest.raises(CollectionError, match=reason):
        api.search(1, 20)


def test_resume_after_block_keeps_pending_and_deduplicates(tmp_path: Path) -> None:
    fake = Fake()
    fake.fail_id = "2"
    store = FileStore(tmp_path, fingerprint="config", resume=False)
    result = run(client(fake), store, max_pages=2, max_cars=10, pagesize=20)
    assert result["status"] == "challenge"
    assert result["records_written"] == 1
    assert result["pending_details"] == 1
    assert result["next_page"] == 2
    assert result["full_run"] is False
    store.close()
    fake = Fake()
    store = FileStore(tmp_path, fingerprint="config", resume=True)
    result = run(client(fake), store, max_pages=2, max_cars=10, pagesize=20)
    assert fake.calls[0][1]["infoid"] == "2"  # drain durable queue before advancing pages
    assert result["status"] == "api_end_reached"
    assert result["records_written"] == 3
    assert result["list_occurrences"] == 4
    assert result["pending_details"] == 0
    assert [p["infoid"] for u, p in fake.calls if u == DETAIL_URL] == ["2", "3"]
    exported = [json.loads(line) for line in (tmp_path / "cars.jsonl").read_text().splitlines()]
    assert [r["source_id"] for r in exported] == ["1", "2", "3"]
    assert all(r["listing"]["price"]["currency"] == "CNY" for r in exported)
    store.close()


def test_record_limit_resume_and_page_limit(tmp_path: Path) -> None:
    store = FileStore(tmp_path, fingerprint="c", resume=False)
    assert collect(client(Fake()), store, max_pages=1, max_cars=1, pagesize=20) == "car_limit"
    assert store.pending() == "2"
    assert collect(client(Fake()), store, max_pages=1, max_cars=10, pagesize=20) == "page_limit"
    assert store.written == 2
    with pytest.raises(CollectionError, match="limits_below_saved_progress"):
        collect(client(Fake()), store, max_pages=1, max_cars=1, pagesize=20)
    assert (
        collect(client(Fake()), store, max_pages=2, max_cars=10, pagesize=20, stopped=lambda: True)
        == "interrupted"
    )
    store.close()


def test_repeated_page_transaction_rolled_back(tmp_path: Path) -> None:
    store = FileStore(tmp_path, fingerprint="c", resume=False)
    store.add_page(SearchPage(1, 3, 3, ("1",)))
    with pytest.raises(CollectionError, match="repeated_page_without_new_ids"):
        store.add_page(SearchPage(2, 3, 3, ("1",)))
    assert store.next_page == 2
    assert store.summary()["unique_ids"] == 1
    with pytest.raises(CollectionError, match="checkpoint_page_mismatch"):
        store.add_page(SearchPage(3, 3, 3, ("2",)))
    store.close()


def test_writer_lock_and_resume_fingerprint(tmp_path: Path) -> None:
    store = FileStore(tmp_path, fingerprint="c", resume=False)
    with pytest.raises(CollectionError, match="output_in_use"):
        FileStore(tmp_path, fingerprint="c", resume=True)
    store.close()
    with pytest.raises(CollectionError, match="output_exists_use_resume"):
        FileStore(tmp_path, fingerprint="c", resume=False)
    with pytest.raises(CollectionError, match="resume_configuration_mismatch"):
        FileStore(tmp_path, fingerprint="changed", resume=True)
    with pytest.raises(CollectionError, match="nothing_to_resume"):
        FileStore(tmp_path / "missing", fingerprint="c", resume=True)


def test_id_mismatch_and_bad_ids() -> None:
    api = Che168Api(lambda u, p: reply(detail("2")), Signer.bundled(), delay=0)
    with pytest.raises(CollectionError, match="detail_id_mismatch"):
        api.detail("1")
    for value in (None, True, "../file", "1.2", "0", -1):
        with pytest.raises(CollectionError, match="invalid_vehicle_id"):
            identifier(value)


def test_proxy_encoding_and_validation(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("FLOPPY_USERNAME", "testuser")
    monkeypatch.setenv("FLOPPY_PASSWORD", "a@b:c")
    monkeypatch.setenv("FLOPPY_HOST", "proxy.example:10080")
    assert "country-CN" in proxy_url("test")
    assert "a%40b%3Ac" in proxy_url("test")
    with pytest.raises(CollectionError, match="invalid_proxy_session"):
        proxy_url("a-b")
    monkeypatch.setenv("FLOPPY_HOST", "bad@host")
    with pytest.raises(CollectionError, match="proxy_configuration"):
        proxy_url("test")


def test_signer_is_deterministic_and_signs_all_parameters() -> None:
    signer = Signer.bundled()
    assert signer.version == "11.41.5"
    assert len(signer.bundle_sha256) == 64
    a = signer.sign({"pageindex": "1", "pagesize": "20"})
    b = signer.sign({"pagesize": "20", "pageindex": "1"})
    assert a["_sign"] == b["_sign"]
    assert a["_sign"] != signer.sign({"pageindex": "2", "pagesize": "20"})["_sign"]
    assert signer.salt not in repr(signer)
