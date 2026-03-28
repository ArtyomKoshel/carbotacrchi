from __future__ import annotations

import json
from dataclasses import dataclass, field, asdict


@dataclass
class CarLot:
    id: str
    source: str
    make: str
    model: str
    year: int
    price: int
    price_krw: int = 0
    mileage: int = 0
    fuel: str | None = None
    transmission: str | None = None
    body_type: str | None = None
    drive_type: str | None = None
    engine_volume: float | None = None
    color: str | None = None
    location: str | None = None
    lot_url: str = ""
    image_url: str | None = None
    vin: str | None = None
    damage: str | None = None
    secondary_damage: str | None = None
    title: str = "Clean"
    document: str | None = None
    trim: str | None = None
    cylinders: int | None = None
    has_keys: bool | None = None
    retail_value: int | None = None
    repair_cost: int | None = None
    raw_data: dict = field(default_factory=dict)

    def to_db_row(self) -> dict:
        raw_json = json.dumps(self.raw_data, ensure_ascii=False, default=str) if self.raw_data else None
        return {
            "id": self.id,
            "source": self.source,
            "make": self.make,
            "model": self.model,
            "year": self.year,
            "price": self.price,
            "mileage": self.mileage,
            "vin": self.vin,
            "body_type": self.body_type,
            "transmission": self.transmission,
            "fuel": self.fuel,
            "drive_type": self.drive_type,
            "damage": self.damage,
            "secondary_damage": self.secondary_damage,
            "title": self.title,
            "document": self.document,
            "location": self.location,
            "color": self.color,
            "trim": self.trim,
            "engine_volume": self.engine_volume,
            "cylinders": self.cylinders,
            "has_keys": self.has_keys,
            "retail_value": self.retail_value,
            "repair_cost": self.repair_cost,
            "image_url": self.image_url,
            "lot_url": self.lot_url,
            "raw_data": raw_json,
            "price_krw": self.price_krw,
        }

    def merge_details(self, details: dict) -> None:
        for key, value in details.items():
            if value is not None and hasattr(self, key):
                setattr(self, key, value)
