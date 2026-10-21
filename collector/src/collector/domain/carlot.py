"""CarLot v1 — the record the collector hands to the core (ADR-0002).

Platform-neutral: every adapter maps its source to this shape, the core
validates it against the generated JSON Schema (contracts/carlot.v1.schema.json)
and is the only writer of `lots`. Values outside the documented ranges are an
adapter bug or junk from the source; adapters drop them to None (keeping the
original in `extra`) instead of sending them.
"""

from __future__ import annotations

import re
from datetime import date
from enum import StrEnum
from typing import Annotated, Any, Final, Literal

from pydantic import (
    AwareDatetime,
    BaseModel,
    ConfigDict,
    Field,
    HttpUrl,
    model_validator,
)

CONTRACT: Final = "carlot/1"

# ISO 3779: 17 characters, no I, O or Q.
VIN_RE: Final = re.compile(r"^[A-HJ-NPR-Z0-9]{17}$")
SOURCE_RE: Final = r"^[a-z][a-z0-9_]{1,31}$"
CURRENCY_RE: Final = r"^[A-Z]{3}$"
MONEY_MAX: Final = 10_000_000_000


class _Model(BaseModel):
    model_config = ConfigDict(extra="forbid", frozen=True, str_strip_whitespace=True)


class Fuel(StrEnum):
    GASOLINE = "gasoline"
    DIESEL = "diesel"
    HYBRID = "hybrid"
    PLUGIN_HYBRID = "plugin_hybrid"
    ELECTRIC = "electric"
    LPG = "lpg"
    HYDROGEN = "hydrogen"
    CNG = "cng"
    OTHER = "other"


class Transmission(StrEnum):
    AUTOMATIC = "automatic"
    MANUAL = "manual"
    CVT = "cvt"
    DCT = "dct"
    OTHER = "other"


class DriveType(StrEnum):
    FWD = "fwd"
    RWD = "rwd"
    AWD = "awd"
    FOUR_WD = "4wd"


class BodyType(StrEnum):
    SEDAN = "sedan"
    SUV = "suv"
    HATCHBACK = "hatchback"
    WAGON = "wagon"
    COUPE = "coupe"
    CONVERTIBLE = "convertible"
    VAN = "van"
    MINIVAN = "minivan"
    PICKUP = "pickup"
    TRUCK = "truck"
    CARGO = "cargo"
    KEI = "kei"  # Korean light car (경차)
    OTHER = "other"


class SellType(StrEnum):
    SALE = "sale"
    LEASE = "lease"
    RENTAL = "rental"
    BUSINESS = "business"
    UNDER_CONTRACT = "under_contract"
    INSURANCE_HIDE = "insurance_hide"
    AUCTION = "auction"


class LegalStatus(StrEnum):
    CLEAN = "clean"
    LIEN = "lien"
    SEIZURE = "seizure"


class SelectionPhase(StrEnum):
    PRE = "pre"
    POST = "post"


class RejectAction(StrEnum):
    """What the deciding rule asks for (parse_filters.action)."""

    SKIP = "skip"  # do not store a new lot; deactivate an existing one
    MARK_INACTIVE = "mark_inactive"  # store the lot, inactive


# JSON Schema "date-time" format checks are optional in many validators; the
# pattern makes "timezone required" hold for every consumer.
TzDatetime = Annotated[AwareDatetime, Field(json_schema_extra={"pattern": r"(Z|[+-]\d{2}:\d{2})$"})]
Vin = Annotated[str, Field(pattern=VIN_RE.pattern, description="ISO 3779 VIN: 17 chars, no I/O/Q")]
NonEmpty = Annotated[str, Field(min_length=1, max_length=200)]


class Money(_Model):
    """Integer amount in the currency's smallest common unit (KRW has no minor unit)."""

    amount: int = Field(ge=0, le=MONEY_MAX)
    currency: str = Field(default="KRW", pattern=CURRENCY_RE)


class Vehicle(_Model):
    """What the car is. Labels stay in the source's language; the core maps them to its catalog."""

    make: NonEmpty
    model: NonEmpty
    model_group: NonEmpty | None = None
    model_en: NonEmpty | None = None
    badge_group: NonEmpty | None = None
    badge: NonEmpty | None = None
    trim: NonEmpty | None = None
    trim_en: NonEmpty | None = None
    year: int | None = Field(
        default=None, ge=1950, le=2100, description="None when the source does not give it"
    )
    first_registration: date | None = None
    vin: Vin | None = None
    plate_number: str | None = Field(default=None, max_length=20)
    fuel: Fuel | None = None
    transmission: Transmission | None = None
    drive_type: DriveType | None = None
    body_type: BodyType | None = None
    engine_volume_l: float | None = Field(default=None, gt=0, le=10)
    seat_count: int | None = Field(default=None, ge=1, le=60)
    color: str | None = Field(default=None, max_length=30)
    seat_color: str | None = Field(default=None, max_length=30)


class Listing(_Model):
    """The offer on the platform."""

    price: Money
    mileage_km: int = Field(ge=0, le=2_000_000)
    location: str | None = Field(default=None, max_length=100)
    listed_at: TzDatetime | None = None
    sell_type: SellType | None = None
    sell_type_raw: str | None = Field(default=None, max_length=200)
    retail_value: Money | None = None
    image_url: HttpUrl | None = None
    photos: tuple[HttpUrl, ...] = ()
    options: tuple[str, ...] = ()
    paid_options: tuple[str, ...] = ()


class History(_Model):
    has_accident: bool | None = None
    flood: bool | None = None
    total_loss: bool | None = None
    owners_count: int | None = Field(default=None, ge=0, le=99)
    insurance_count: int | None = Field(default=None, ge=0, le=999)
    repair_cost: Money | None = None
    lien_status: LegalStatus | None = None
    seizure_status: LegalStatus | None = None
    observed_at: TzDatetime | None = Field(
        default=None,
        description="When this history was fetched from the source; None = not fetched in this run.",
    )


class Inspection(_Model):
    cert_no: str | None = Field(default=None, max_length=60)
    inspected_on: date | None = None
    valid_from: date | None = None
    valid_until: date | None = None
    report_url: HttpUrl | None = None
    first_registration: date | None = None
    mileage_km: int | None = Field(default=None, ge=0, le=2_000_000)
    insurance_fee: Money | None = None
    has_accident: bool | None = None
    has_outer_damage: bool | None = None
    has_flood: bool | None = None
    has_fire: bool | None = None
    has_tuning: bool | None = None
    has_recall: bool = False
    own_damage_cost: Money | None = None
    other_damage_cost: Money | None = None
    accident_detail: str | None = None
    outer_detail: str | None = None
    details: dict[str, Any] = Field(default_factory=dict)


class Selection(_Model):
    """Verdict of the selection rules (ADR-0004). The core sets is_active from it, once."""

    # Same cross-field rule for every consumer of the JSON Schema, not only pydantic.
    model_config = ConfigDict(
        json_schema_extra={
            "if": {"properties": {"passed": {"const": False}}, "required": ["passed"]},
            "then": {
                "required": ["rule_ids", "action", "phase"],
                "properties": {"rule_ids": {"minItems": 1}, "action": {"type": "string"}},
            },
            "else": {"properties": {"rule_ids": {"maxItems": 0}, "action": {"type": "null"}}},
        },
    )

    passed: bool
    phase: SelectionPhase | None = None
    action: RejectAction | None = None
    rule_ids: tuple[int, ...] = Field(default=(), description="Rules that rejected the lot")
    flagged_rule_ids: tuple[int, ...] = Field(default=(), description="'flag' rules that matched")

    @model_validator(mode="after")
    def _rejection_is_explained(self) -> Selection:
        if self.passed and (self.rule_ids or self.action is not None):
            raise ValueError("a passed lot has no rejecting rules or action")
        if not self.passed and not (self.rule_ids and self.action and self.phase):
            raise ValueError("a rejected lot names its phase, action and rejecting rule ids")
        return self


class CarLotV1(_Model):
    contract: Literal["carlot/1"] = CONTRACT
    source: str = Field(pattern=SOURCE_RE)
    source_id: str = Field(min_length=1, max_length=100)
    url: HttpUrl
    observed_at: TzDatetime
    run_id: int | None = Field(default=None, ge=1)
    vehicle: Vehicle
    listing: Listing
    history: History = History()
    inspection: Inspection | None = None
    selection: Selection = Selection(passed=True)
    extra: dict[str, Any] = Field(
        default_factory=dict,
        description="Source-specific fields that have no column yet; the core keeps them in raw_data.",
    )

    @property
    def key(self) -> tuple[str, str]:
        return (self.source, self.source_id)
