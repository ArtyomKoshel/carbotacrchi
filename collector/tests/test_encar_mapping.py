"""Encar API JSON → draft → CarLot v1."""

from __future__ import annotations

import copy
import json
from datetime import UTC, date, datetime
from pathlib import Path
from typing import Any

import pytest
from hypothesis import given, settings
from hypothesis import strategies as st
from jsonschema import Draft202012Validator, FormatChecker

from collector import contracts
from collector.adapters.encar import mapping as m
from collector.domain.carlot import (
    BodyType,
    DriveType,
    Fuel,
    RejectAction,
    Selection,
    SelectionPhase,
    SellType,
    Transmission,
)

FIX = Path(__file__).parent / "fixtures" / "encar"
NOW = datetime(2026, 10, 21, 9, 0, tzinfo=UTC)


def load(name: str) -> dict[str, Any]:
    data: dict[str, Any] = json.loads((FIX / f"{name}.json").read_text(encoding="utf-8"))
    return data


def full_draft() -> tuple[m.EncarDraft, m.InspectionDraft]:
    lot = m.from_search(load("search_item"))
    m.apply_detail(lot, load("detail"))
    record = m.apply_record(lot, load("record"))
    m.apply_inspection(lot, load("inspection"), record)
    m.apply_sellingpoint(lot, load("sellingpoint"))
    return lot, record


# ── helpers ────────────────────────────────────────────────────────────────


@pytest.mark.parametrize(
    ("raw", "badge", "expected"),
    [
        ("더 뉴 쏘렌토 4세대 가솔린 2.5T 4WD", "가솔린 2.5T 4WD", "쏘렌토 4세대"),
        ("올 뉴 K7 2.4", "", "K7 2.4"),
        ("더 뉴 더 뉴 아반떼", "", "아반떼"),
        ("가솔린 2.0", "가솔린 2.0", "가솔린 2.0"),  # nothing left → keep the raw string
        ("  그랜저  ", "", "그랜저"),
    ],
)
def test_clean_model(raw: str, badge: str, expected: str) -> None:
    assert m.clean_model(raw, badge, "") == expected


def test_lookup_is_case_insensitive_and_unmapped_is_none() -> None:
    assert m.transmission("cvt") == "cvt"
    assert m.fuel("  가솔린 ") == "gasoline"
    assert m.fuel("steam") is None
    assert m.fuel("") is None
    assert m.fuel(None) is None


def test_color_strips_a_trailing_note_and_keeps_unknown_labels() -> None:
    assert m.color("흰색") == "White"
    assert m.color("흰색 (펄)") == "White"
    assert m.color("무지개") == "무지개"
    assert m.color("") is None


def test_color_knows_the_old_parser_names() -> None:
    # without them a stored "Silver" came back as "은회색" and logged a change
    assert m.color("은회색") == "Silver"
    assert m.color("하늘색") == "Blue"
    assert m.color("흰색투톤") == "White Two-Tone"


def test_price_from_man_won() -> None:
    assert m.price_krw_from_man(1890) == 18_900_000
    assert m.price_krw_from_man("12.5") == 125_000
    assert m.price_krw_from_man("x") == 0


# ── search item ────────────────────────────────────────────────────────────


def test_from_search_maps_list_fields() -> None:
    lot = m.from_search(load("search_item"))
    assert lot.id == "40011223"
    assert lot.make == "현대"
    assert lot.model == "쏘나타 (DN8)"
    assert lot.year == 2021
    assert lot.price == 18_900_000
    assert lot.mileage == 45_210
    assert lot.fuel == "gasoline"
    assert lot.transmission == "automatic"
    assert lot.color == "White"
    assert lot.trim == "프리미엄"
    assert lot.sell_type is SellType.SALE
    assert lot.image_url == m.photo_url("/carpicture08/pic4001/40011223_001.jpg")
    assert lot.lot_url == "https://fem.encar.com/cars/detail/40011223"
    assert lot.raw_data["condition"] == ["Record", "Inspection"]


def test_from_search_zeroes_absurd_prices_and_missing_years() -> None:
    item = load("search_item") | {"Price": 2_000_000_000, "FormYear": None, "Year": None}
    lot = m.from_search(item)
    assert lot.price == 0
    assert lot.year == 0


@pytest.mark.parametrize(
    ("sell", "ad", "cond", "expected"),
    [
        ("일반", "일반", ["Lease"], SellType.LEASE),
        ("일반", "렌트카", [], SellType.RENTAL),
        ("일반", "계약중", [], SellType.UNDER_CONTRACT),
        ("업자매입", None, None, SellType.BUSINESS),
        ("직거래차량", None, None, SellType.SALE),  # substring match
        (None, None, None, SellType.SALE),
    ],
)
def test_sell_type_precedence(
    sell: str | None, ad: str | None, cond: list[str] | None, expected: SellType
) -> None:
    from collector.adapters.encar.sell_type import normalize

    assert normalize(sell, ad, cond)[0] is expected


# ── enrichment ─────────────────────────────────────────────────────────────


def test_detail_enrichment() -> None:
    lot = m.from_search(load("search_item"))
    m.apply_detail(lot, load("detail"))
    assert lot.engine_volume == 2.0
    assert lot.body_type == "sedan"
    assert lot.drive_type == "fwd"
    assert lot.seat_count == 5
    assert lot.vin == "KMHL341CBMA000001"
    assert lot.plate_number == "00가0000"
    assert lot.location == "경기 성남시"
    assert lot.listed_at == "2026-09-14"
    assert lot.photos is not None
    assert len(lot.photos) == 2  # duplicates dropped, order kept
    assert lot.options == ["001", "014", "059"]
    assert lot.paid_options == ["C1", "P9"]
    assert lot.retail_value == 32_000_000
    assert lot.trim_en == "Premium"
    assert lot.raw_data["ad_status"] == "ADVERTISE"


def test_detail_keeps_drive_from_elsewhere_and_records_inner_photo_id() -> None:
    lot = m.from_search(load("search_item"))
    lot.drive_type = "awd"
    detail = copy.deepcopy(load("detail"))
    detail["photos"][0]["path"] = "/carpicture08/pic3999/39990001_001.jpg"
    m.apply_detail(lot, detail)
    assert lot.drive_type == "awd"
    assert lot.raw_data["inspect_vehicle_id"] == "39990001"


def test_record_enrichment() -> None:
    lot = m.from_search(load("search_item"))
    record = m.apply_record(lot, load("record"))
    assert lot.has_accident is True
    assert lot.insurance_count == 2
    assert lot.owners_count == 2
    assert lot.flood_history is False
    assert lot.total_loss_history is False
    assert lot.lien_status == "clean"
    assert lot.seizure_status == "clean"
    assert lot.repair_cost == 450_000
    assert lot.first_reg_date == "2021-03-02"
    assert record.accident_detail == "2023-05-01 [my-fault] ₩350,000\n2024-01-10 [other-fault] ₩100,000"
    assert record.my_accident_cost == 350_000


def test_record_flags_lien_seizure_and_flood() -> None:
    lot = m.from_search(load("search_item"))
    m.apply_record(
        lot, load("record") | {"loan": 1, "robberCnt": 2, "floodPartLossCnt": 1, "totalLossCnt": 1}
    )
    assert (lot.lien_status, lot.seizure_status, lot.flood_history, lot.total_loss_history) == (
        "lien",
        "seizure",
        True,
        True,
    )


def test_inspection_enrichment() -> None:
    lot, record = full_draft()
    assert lot.has_accident is True, "the record API's answer wins over master.accdient"
    assert record.has_accident is False
    assert record.has_outer_damage is True
    assert record.outer_detail == "후드: 교환"
    assert record.cert_no == "1234567890"
    assert record.inspection_date == "2026-09-10"
    assert (record.valid_from, record.valid_until) == ("2026-09-10", "2027-09-09")
    assert record.inspection_mileage == 45_100
    assert record.has_recall is True
    assert lot.raw_data["engine_code"] == "G4NA"
    assert lot.raw_data["recall_status"] == ["이행"]
    assert lot.raw_data["mechanical_issues"] == ["엔진/오일 → 미세누유"]


def test_inspection_rejects_short_cert_numbers_and_bad_dates() -> None:
    lot = m.from_search(load("search_item"))
    record = m.InspectionDraft()
    insp = copy.deepcopy(load("inspection"))
    insp["master"]["supplyNum"] = "123"
    insp["master"]["detail"]["validityStartDate"] = "20261399"
    m.apply_inspection(lot, insp, record)
    assert record.cert_no is None
    assert record.valid_from is None
    assert lot.has_accident is None, "an undamaged frame says nothing about claims"
    assert record.has_accident is False
    insp["master"]["accdient"] = True
    m.apply_inspection(lot, insp, record)
    assert lot.has_accident is True, "an accident the inspection found reaches the lot"


def test_sellingpoint_gives_drive_and_sentence() -> None:
    lot = m.from_search(load("search_item"))
    m.apply_sellingpoint(lot, load("sellingpoint"))
    assert lot.drive_type == "awd"
    assert lot.raw_data["selling_point"] == "무사고 1인 신조"


# ── contract ───────────────────────────────────────────────────────────────


def test_full_draft_becomes_a_valid_carlot() -> None:
    lot, record = full_draft()
    carlot = m.to_carlot(lot, record, observed_at=NOW, history_observed_at=NOW, run_id=17)

    assert carlot.key == ("encar", "40011223")
    assert carlot.vehicle.fuel is Fuel.GASOLINE
    assert carlot.vehicle.transmission is Transmission.AUTOMATIC
    assert carlot.vehicle.drive_type is DriveType.AWD
    assert carlot.vehicle.body_type is BodyType.SEDAN
    assert carlot.vehicle.first_registration == date(2021, 3, 2)
    assert carlot.listing.price.amount == 18_900_000
    assert carlot.listing.listed_at == datetime(2026, 9, 14, tzinfo=m.KST)
    assert carlot.history.repair_cost is not None
    assert carlot.history.repair_cost.amount == 450_000
    assert carlot.inspection is not None
    assert carlot.inspection.own_damage_cost is not None
    assert carlot.inspection.own_damage_cost.amount == 350_000
    assert carlot.extra["engine_code"] == "G4NA"

    schema = json.loads(contracts.CARLOT_SCHEMA_PATH.read_text(encoding="utf-8"))
    errors = list(
        Draft202012Validator(schema, format_checker=FormatChecker()).iter_errors(
            json.loads(carlot.model_dump_json())
        )
    )
    assert errors == []


def test_junk_values_move_to_extra_instead_of_failing() -> None:
    lot = m.from_search(load("search_item") | {"FormYear": None, "Year": None})
    lot.vin = "KMH1"
    lot.engine_volume = 33.4
    lot.seat_count = 0
    lot.owners_count = 150
    lot.insurance_count = 5000
    lot.color = "x" * 40
    lot.fuel = "cng-ish"
    lot.raw_data["_internal"] = 1
    lot.raw_data["photos"] = ["dup"]

    carlot = m.to_carlot(lot, None, observed_at=NOW)

    assert carlot.vehicle.vin is None
    assert carlot.vehicle.engine_volume_l is None
    assert carlot.vehicle.year is None
    assert carlot.vehicle.fuel is None
    assert carlot.history.owners_count is None
    assert carlot.history.insurance_count is None
    assert {k: carlot.extra[k] for k in ("vin_raw", "engine_volume_l_raw", "seat_count_raw", "year_raw")} == {
        "vin_raw": "KMH1",
        "engine_volume_l_raw": 33.4,
        "seat_count_raw": 0,
        "year_raw": 0,
    }
    assert carlot.extra["fuel_raw"] == "cng-ish"
    assert "_internal" not in carlot.extra
    assert "photos" not in carlot.extra


def test_selection_travels_with_the_record() -> None:
    lot = m.from_search(load("search_item"))
    sel = Selection(passed=False, phase=SelectionPhase.PRE, action=RejectAction.SKIP, rule_ids=(7,))
    assert m.to_carlot(lot, None, observed_at=NOW, selection=sel).selection == sel


search_items = st.fixed_dictionaries(
    {"Id": st.integers(min_value=1, max_value=10**9)},
    optional={
        "Manufacturer": st.text(max_size=20),
        "Model": st.text(max_size=60),
        "Badge": st.text(max_size=30),
        "FormYear": st.one_of(st.none(), st.text(max_size=6), st.integers(1900, 2100).map(str)),
        "Price": st.one_of(st.none(), st.integers(min_value=0, max_value=3 * 10**9)),
        "Mileage": st.one_of(st.none(), st.integers(min_value=0, max_value=10**7)),
        "FuelType": st.one_of(st.none(), st.text(max_size=10)),
        "Condition": st.lists(st.sampled_from(["Record", "Inspection", "Lease", "Rent"]), max_size=3),
        "SellType": st.one_of(st.none(), st.sampled_from(["일반", "리스", "렌트", "x"])),
    },
)


@settings(max_examples=200, deadline=None)
@given(item=search_items)
def test_any_search_item_maps_without_crashing(item: dict[str, Any]) -> None:
    lot = m.from_search(item)
    if lot.make.strip() and lot.model.strip():
        m.to_carlot(lot, None, observed_at=NOW)  # junk goes to extra; never a crash
