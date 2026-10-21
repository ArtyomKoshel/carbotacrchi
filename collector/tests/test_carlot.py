"""CarLot v1 domain model: what is accepted, what is refused, how it serializes."""

from __future__ import annotations

import copy
from typing import Any

import pytest
from hypothesis import given
from hypothesis import strategies as st
from pydantic import ValidationError

from collector.domain.carlot import CONTRACT, CarLotV1, DriveType, Fuel, Money, Selection, SelectionPhase


def test_full_fixture_parses_with_typed_fields(full_lot: dict[str, Any]) -> None:
    lot = CarLotV1.model_validate(full_lot)
    assert lot.contract == CONTRACT
    assert lot.key == ("encar", "39847215")
    assert lot.vehicle.fuel is Fuel.GASOLINE
    assert lot.vehicle.drive_type is DriveType.FWD
    assert lot.listing.price == Money(amount=18_900_000, currency="KRW")
    assert lot.inspection is not None
    assert lot.inspection.mileage_km == 45_100


def test_defaults_for_minimal_lot() -> None:
    lot = CarLotV1.model_validate(
        {
            "source": "encar",
            "source_id": "1",
            "url": "https://fem.encar.com/cars/detail/1",
            "observed_at": "2026-10-21T00:00:00Z",
            "vehicle": {"make": "기아", "model": "K5", "year": 2014},
            "listing": {"price": {"amount": 5}, "mileage_km": 0},
        }
    )
    assert lot.listing.price.currency == "KRW"
    assert lot.selection.passed is True
    assert lot.history.has_accident is None
    assert lot.listing.photos == ()


def test_round_trip_through_json_is_lossless(full_lot: dict[str, Any]) -> None:
    lot = CarLotV1.model_validate(full_lot)
    again = CarLotV1.model_validate_json(lot.model_dump_json())
    assert again == lot


def test_model_is_immutable(full_lot: dict[str, Any]) -> None:
    lot = CarLotV1.model_validate(full_lot)
    with pytest.raises(ValidationError):
        lot.source_id = "other"  # type: ignore[misc]


def test_whitespace_is_stripped(full_lot: dict[str, Any]) -> None:
    data = copy.deepcopy(full_lot)
    data["vehicle"]["make"] = "  현대 "
    assert CarLotV1.model_validate(data).vehicle.make == "현대"


@pytest.mark.parametrize(
    ("path", "value"),
    [
        (("vehicle", "vin"), "KMH1"),
        (("vehicle", "vin"), "KMHL341CBMA12345O"),  # O is not allowed in a VIN
        (("vehicle", "engine_volume_l"), 33.4),
        (("vehicle", "engine_volume_l"), 0),
        (("vehicle", "year"), 1949),
        (("vehicle", "seat_count"), 0),
        (("vehicle", "fuel"), "petrol"),
        (("vehicle", "make"), ""),
        (("listing", "mileage_km"), -1),
        (("history", "owners_count"), 100),
        (("source",), "Encar Korea"),
        (("observed_at",), "2026-10-21T09:15:00"),
        (("contract",), "carlot/2"),
    ],
)
def test_out_of_contract_values_are_refused(
    full_lot: dict[str, Any],
    path: tuple[str, ...],
    value: Any,
) -> None:
    data = copy.deepcopy(full_lot)
    target = data
    for part in path[:-1]:
        target = target[part]
    target[path[-1]] = value
    with pytest.raises(ValidationError):
        CarLotV1.model_validate(data)


def test_unknown_fields_are_refused_at_every_level(full_lot: dict[str, Any]) -> None:
    for section in (None, "vehicle", "listing", "history"):
        data = copy.deepcopy(full_lot)
        (data if section is None else data[section])["dealer_phone"] = "010-0000"
        with pytest.raises(ValidationError):
            CarLotV1.model_validate(data)


def test_rejection_must_name_its_rules() -> None:
    with pytest.raises(ValidationError):
        Selection(passed=False)
    assert Selection(passed=False, phase=SelectionPhase.PRE, rule_ids=(3,)).rule_ids == (3,)


@given(amount=st.integers(min_value=0, max_value=10_000_000_000), currency=st.from_regex(r"\A[A-Z]{3}\Z"))
def test_money_accepts_its_whole_range(amount: int, currency: str) -> None:
    assert Money(amount=amount, currency=currency).amount == amount


@given(amount=st.integers(max_value=-1))
def test_money_refuses_negative(amount: int) -> None:
    with pytest.raises(ValidationError):
        Money(amount=amount)
