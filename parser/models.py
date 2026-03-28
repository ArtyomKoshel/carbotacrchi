from __future__ import annotations

import json
from dataclasses import dataclass, field


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

    # Technical specs
    fuel: str | None = None
    transmission: str | None = None
    body_type: str | None = None
    drive_type: str | None = None
    engine_volume: float | None = None
    cylinders: int | None = None
    color: str | None = None
    seat_color: str | None = None
    trim: str | None = None

    # Location & links
    location: str | None = None
    lot_url: str = ""
    image_url: str | None = None

    # Registration & documents
    vin: str | None = None
    plate_number: str | None = None
    registration_date: str | None = None
    title: str = "Clean"
    document: str | None = None
    lien_status: str | None = None
    seizure_status: str | None = None
    tax_paid: bool | None = None

    # Condition & history
    damage: str | None = None
    secondary_damage: str | None = None
    accident_status: str | None = None
    total_loss_history: bool | None = None
    flood_history: bool | None = None
    owners_count: int | None = None
    insurance_count: int | None = None
    mileage_grade: str | None = None
    has_keys: bool | None = None

    # Pricing
    retail_value: int | None = None
    repair_cost: int | None = None
    new_car_price_ratio: int | None = None
    ai_price_min: int | None = None
    ai_price_max: int | None = None

    # Options
    options: list | None = None

    # Dealer info
    dealer_name: str | None = None
    dealer_company: str | None = None
    dealer_location: str | None = None
    dealer_phone: str | None = None
    dealer_description: str | None = None

    # Raw data
    raw_data: dict = field(default_factory=dict)

    def to_db_row(self) -> dict:
        raw_json = json.dumps(self.raw_data, ensure_ascii=False, default=str) if self.raw_data else None
        options_json = json.dumps(self.options, ensure_ascii=False) if self.options else None

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
            # Extended fields
            "plate_number": self.plate_number,
            "registration_date": self.registration_date,
            "seat_color": self.seat_color,
            "lien_status": self.lien_status,
            "seizure_status": self.seizure_status,
            "tax_paid": self.tax_paid,
            "accident_status": self.accident_status,
            "total_loss_history": self.total_loss_history,
            "flood_history": self.flood_history,
            "owners_count": self.owners_count,
            "insurance_count": self.insurance_count,
            "mileage_grade": self.mileage_grade,
            "new_car_price_ratio": self.new_car_price_ratio,
            "ai_price_min": self.ai_price_min,
            "ai_price_max": self.ai_price_max,
            "options": options_json,
            "dealer_name": self.dealer_name,
            "dealer_company": self.dealer_company,
            "dealer_location": self.dealer_location,
            "dealer_phone": self.dealer_phone,
            "dealer_description": self.dealer_description,
        }

    def merge_details(self, details: dict) -> None:
        for key, value in details.items():
            if value is not None and hasattr(self, key):
                setattr(self, key, value)
