"""Che168 detail → CarLot v1. Only what Che168 states; unknown stays empty."""

from __future__ import annotations

import re
from datetime import date, datetime, timedelta, timezone
from decimal import Decimal, InvalidOperation
from typing import Any

from pydantic import HttpUrl, ValidationError

from collector.adapters.che168.api import CollectionError, identifier
from collector.domain.carlot import (
    BodyType,
    CarLotV1,
    DriveType,
    Fuel,
    Listing,
    Money,
    Transmission,
    Vehicle,
)

# Only fields needed to interpret the vehicle are retained. No seller contacts or VINs.
SOURCE_FIELDS = (
    "infoid",
    "dealerid",
    "brandid",
    "brandname",
    "seriesid",
    "seriesname",
    "specid",
    "carname",
    "price",
    "mileage",
    "firstregdate",
    "firstregyear",
    "publicdate",
    "displacement",
    "engine",
    "gearbox",
    "drivingmode",
    "fuelname",
    "levelname",
    "colorname",
    "setcount",
    "transfercount",
    # Kept raw until live values are checked; not mapped into the contract yet.
    "cid",
    "cname",
    "pid",
    "countyname",
    "guidanceprice",
    "carusename",
    "isnewcar",
    "environmental",
    "flowmode",
    "firstregstr",
    "isev",
    "batterypower",
    "lifemileage",
    "cartype",
)
CHINA = timezone(timedelta(hours=8))  # no DST
MODEL_YEAR = re.compile(r"(?<!\d)((?:19|20)\d{2})款")
TRANSMISSIONS = {"自动": Transmission.AUTOMATIC, "手动": Transmission.MANUAL}
FUELS = {
    "汽油": Fuel.GASOLINE,
    "柴油": Fuel.DIESEL,
    "纯电动": Fuel.ELECTRIC,
    "油电混合": Fuel.HYBRID,
    "插电式混合动力": Fuel.PLUGIN_HYBRID,
    "插电混动": Fuel.PLUGIN_HYBRID,
    # Range extender: charged from the plug, the engine only generates.
    "增程式": Fuel.PLUGIN_HYBRID,
    # A 48V mild hybrid never drives on electricity: it is the base fuel.
    "汽油+48V轻混系统": Fuel.GASOLINE,
    "柴油+48V轻混系统": Fuel.DIESEL,
}
DRIVES = {
    "前置前驱": DriveType.FWD,
    "前置后驱": DriveType.RWD,
    "后置后驱": DriveType.RWD,
    "中置后驱": DriveType.RWD,
    # Che168 does not tell full-time from part-time four-wheel drive.
    "前置四驱": DriveType.AWD,
    "后置四驱": DriveType.AWD,
    "中置四驱": DriveType.AWD,
    "双电机四驱": DriveType.AWD,
    "三电机四驱": DriveType.AWD,
    "四电机四驱": DriveType.AWD,
}
# Che168 groups colors; a group stays a group rather than picking one of its colors.
COLORS = {
    "黑色": "Black",
    "白色": "White",
    "银/灰色": "Silver/Gray",
    "蓝色": "Blue",
    "红/紫色": "Red/Purple",
    "绿色": "Green",
    "香槟/棕色": "Champagne/Brown",
    "黄/橙色": "Yellow/Orange",
}
# levelname is a size class; only these words name the body. "中型车" (mid-size car) does not.
BODIES = (
    ("SUV", BodyType.SUV),
    ("MPV", BodyType.MINIVAN),
    ("跑车", BodyType.COUPE),
    ("皮卡", BodyType.PICKUP),
)


def scaled_integer(value: Any) -> int:
    try:
        number = Decimal(str(value)) * 10_000
        if not number.is_finite() or number < 0 or number != number.to_integral_value():
            raise CollectionError("invalid_price_or_mileage")
        return int(number)
    except (InvalidOperation, ValueError):
        raise CollectionError("invalid_price_or_mileage") from None


def retail_value(value: Any) -> Money | None:
    """guidanceprice: new-car price in 万 CNY; 0 or anything unusable means Che168 does not know it."""
    try:
        amount = scaled_integer(value)
    except CollectionError:
        return None
    return Money(amount=amount, currency="CNY") if amount > 0 else None


def optional_engine(value: Any) -> float | None:
    try:
        number = float(value)
        return number if 0 < number <= 10 else None
    except (TypeError, ValueError):
        return None


def registration(value: Any) -> date | None:
    # YYYY-MM is month precision. Do not invent a day to satisfy the contract.
    try:
        return date.fromisoformat(str(value))
    except ValueError:
        return None


def model_year(carname: Any) -> int | None:
    """Che168 names the spec «<series> 2018款 …»: the model year it states, not the registration year."""
    match = MODEL_YEAR.search(carname) if isinstance(carname, str) else None
    return int(match.group(1)) if match else None


def color(value: Any) -> str | None:
    """Canonical English color, else the raw label (like Encar)."""
    clean = str(value).strip() if isinstance(value, str) else ""
    return COLORS.get(clean, clean) or None


def body_type(level: Any) -> BodyType | None:
    if not isinstance(level, str):
        return None
    return next((body for word, body in BODIES if word in level), None)


def listed_at(value: Any, observed_at: datetime) -> datetime | None:
    """publicdate is a date or «今天» / «昨天» / «N天前» from the observation day; the day in China."""
    if not isinstance(value, str):
        return None
    today = observed_at.astimezone(CHINA).date()
    days = re.fullmatch(r"([0-9]{1,3})天前", value)
    if value == "今天":
        day = today
    elif value == "昨天":
        day = today - timedelta(days=1)
    elif days:
        day = today - timedelta(days=int(days.group(1)))
    else:
        try:
            day = date.fromisoformat(value)
        except ValueError:
            return None
    return datetime(day.year, day.month, day.day, tzinfo=CHINA)


def location(value: Any) -> str | None:
    return value.strip()[:100] or None if isinstance(value, str) else None


def to_lot(data: dict[str, Any], observed_at: datetime) -> CarLotV1:
    infoid = identifier(data.get("infoid"))
    dealerid = identifier(data.get("dealerid"))
    photos = data.get("piclist")
    if not isinstance(photos, list) or any(not isinstance(p, str) for p in photos):
        raise CollectionError("photo_schema_changed")
    raw = {key: data[key] for key in SOURCE_FIELDS if isinstance(data.get(key), (str, int, float))}
    try:
        urls = tuple(dict.fromkeys(HttpUrl("https:" + p if p.startswith("//") else p) for p in photos))
        seats = data.get("setcount")
        return CarLotV1(
            source="che168",
            source_id=infoid,
            url=HttpUrl(f"https://www.che168.com/dealer/{dealerid}/{infoid}.html"),
            observed_at=observed_at,
            vehicle=Vehicle(
                make=data.get("brandname"),
                model=data.get("seriesname"),
                year=model_year(data.get("carname")),
                first_registration=registration(data.get("firstregdate")),
                engine_volume_l=optional_engine(data.get("displacement")),
                transmission=TRANSMISSIONS.get(str(data.get("gearbox"))),
                fuel=FUELS.get(str(data.get("fuelname"))),
                drive_type=DRIVES.get(str(data.get("drivingmode"))),
                seat_count=seats if type(seats) is int and 1 <= seats <= 60 else None,
                body_type=body_type(data.get("levelname")),
                color=color(data.get("colorname")),
            ),
            listing=Listing(
                price=Money(amount=scaled_integer(data.get("price")), currency="CNY"),
                mileage_km=scaled_integer(data.get("mileage")),
                location=location(data.get("cname")),
                retail_value=retail_value(data.get("guidanceprice")),
                listed_at=listed_at(data.get("publicdate"), observed_at),
                photos=urls,
                image_url=urls[0] if urls else None,
            ),
            extra={
                "che168": raw,
                "collector_method": "api",
                "price_unit": "CNY_yuan",
                "source_price_unit": "wan_CNY",
                "source_mileage_unit": "wan_km",
                "selection_evaluated": False,
            },
        )
    except ValidationError:
        raise CollectionError("invalid_vehicle_fields") from None
