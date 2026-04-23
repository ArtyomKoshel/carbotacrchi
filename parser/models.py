from __future__ import annotations

import json
from dataclasses import dataclass, field
from typing import ClassVar


@dataclass
class CarLot:
    id: str
    source: str
    make: str
    model: str
    year: int
    price: int                    # Always in KRW (canonical)
    mileage: int = 0

    # Year+month compact encoding: int YYYYMM (e.g. 202006). None if unknown.
    registration_year_month: int | None = None

    # Technical specs
    fuel: str | None = None
    transmission: str | None = None
    body_type: str | None = None
    drive_type: str | None = None
    engine_volume: float | None = None
    fuel_economy: float | None = None
    cylinders: int | None = None
    color: str | None = None
    seat_color: str | None = None
    trim: str | None = None

    # Location & links
    location: str | None = None
    lot_url: str = ""
    image_url: str | None = None

    # New first-class columns (extracted from raw_data)
    seat_count: int | None = None
    is_domestic: bool | None = None
    import_type: str | None = None

    # Registration & documents
    vin: str | None = None
    plate_number: str | None = None
    registration_date: str | None = None
    title: str = "Clean"

    # Legal / registration status
    lien_status: str | None = None
    seizure_status: str | None = None
    tax_paid: bool | None = None

    # Condition & history
    damage: str | None = None
    secondary_damage: str | None = None
    has_accident: bool | None = None
    flood_history: bool | None = None
    total_loss_history: bool | None = None
    owners_count: int | None = None
    insurance_count: int | None = None
    mileage_grade: str | None = None

    # Pricing
    retail_value: int | None = None
    repair_cost: int | None = None
    new_car_price_ratio: int | None = None

    # Options
    options: list | None = None
    paid_options: list | None = None

    # Sales model (sale|lease|rental|business|under_contract|insurance_hide|auction)
    sell_type: str | None = None
    sell_type_raw: str | None = None  # raw value from source for debugging

    # Dealer info
    dealer_name: str | None = None
    dealer_company: str | None = None
    dealer_location: str | None = None
    dealer_phone: str | None = None
    dealer_description: str | None = None
    warranty_text: str | None = None

    # Photos — transit field: repository upserts these into `lot_photos`
    # after upsert_batch and does NOT serialize them into raw_data.
    photos: list[str] | None = None

    # Raw data
    raw_data: dict = field(default_factory=dict)

    # Fields that live in their own column / table and must NOT be serialized
    # into raw_data JSON (otherwise each lot row carries kilobytes of duplicate
    # data). Kept as a class-level constant for documentation.
    _RAW_DATA_BLOCKLIST: ClassVar[frozenset[str]] = frozenset({
        "photos",             # -> lot_photos table (via CarLot.photos field)
        "photo_path",         # -> superseded by image_url column
        "photo_count",        # -> COUNT(*) from lot_photos
        "sell_type",          # -> lots.sell_type_raw column
        "manufacturer_kr",    # -> duplicate of make
        "model_kr",           # -> duplicate of model
        "badge_kr",           # -> duplicate of trim
        "model_group_kr",     # -> duplicate of model
        "year_month",         # -> duplicate of registration_year_month
        "origin_price",       # -> duplicate of retail_value
        "domestic",           # -> extracted to is_domestic column
        "import_type",        # -> extracted to import_type column
        "seat_count",         # -> extracted to seat_count column
    })

    def _clean_raw_data(self) -> dict:
        """Return a copy of raw_data with duplicated/obsolete keys removed."""
        if not self.raw_data:
            return {}
        return {k: v for k, v in self.raw_data.items() if k not in self._RAW_DATA_BLOCKLIST}

    def to_db_row(self) -> dict:
        clean_raw = self._clean_raw_data()
        raw_json = json.dumps(clean_raw, ensure_ascii=False, default=str) if clean_raw else None
        options_json = json.dumps(self.options, ensure_ascii=False) if self.options else None
        paid_options_json = json.dumps(self.paid_options, ensure_ascii=False) if self.paid_options else None

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
            "has_accident": self.has_accident,
            "flood_history": self.flood_history,
            "owners_count": self.owners_count,
            "insurance_count": self.insurance_count,
            "title": self.title,
            "location": self.location,
            "color": self.color,
            "seat_color": self.seat_color,
            "trim": self.trim,
            "cylinders": self.cylinders,
            "engine_volume": self.engine_volume,
            "fuel_economy": self.fuel_economy,
            "lien_status": self.lien_status,
            "seizure_status": self.seizure_status,
            "tax_paid": self.tax_paid,
            "total_loss_history": self.total_loss_history,
            "mileage_grade": self.mileage_grade,
            "retail_value": self.retail_value,
            "repair_cost": self.repair_cost,
            "new_car_price_ratio": self.new_car_price_ratio,
            "registration_year_month": self.registration_year_month,
            "image_url": self.image_url,
            "lot_url": self.lot_url,
            "raw_data": raw_json,
            "plate_number": self.plate_number,
            "registration_date": self.registration_date,
            "options": options_json,
            "paid_options": paid_options_json,
            "dealer_name": self.dealer_name,
            "dealer_company": self.dealer_company,
            "dealer_location": self.dealer_location,
            "dealer_phone": self.dealer_phone,
            "dealer_description": self.dealer_description,
            "warranty_text": self.warranty_text,
            "sell_type": self.sell_type,
            "sell_type_raw": self.sell_type_raw,
            "seat_count": self.seat_count,
            "is_domestic": self.is_domestic,
            "import_type": self.import_type,
        }

    def merge_details(self, details: dict) -> None:
        for key, value in details.items():
            if value is not None and hasattr(self, key):
                setattr(self, key, value)


@dataclass
class InspectionRecord:
    lot_id: str
    source: str = "carmodoo"

    cert_no: str | None = None
    inspection_date: str | None = None
    valid_from: str | None = None
    valid_until: str | None = None
    report_url: str | None = None

    first_registration: str | None = None
    inspection_mileage: int | None = None
    insurance_fee: int | None = None

    has_accident: bool | None = None
    has_outer_damage: bool | None = None
    has_flood: bool | None = None
    has_fire: bool | None = None
    has_tuning: bool | None = None

    accident_detail: str | None = None
    outer_detail: str | None = None

    details: dict = field(default_factory=dict)

    def to_db_row(self) -> dict:
        details_json = (
            json.dumps(self.details, ensure_ascii=False, default=str)
            if self.details else None
        )
        return {
            "lot_id":             self.lot_id,
            "source":             self.source,
            "cert_no":            self.cert_no,
            "inspection_date":    self.inspection_date,
            "valid_from":         self.valid_from,
            "valid_until":        self.valid_until,
            "report_url":         self.report_url,
            "first_registration": self.first_registration,
            "inspection_mileage": self.inspection_mileage,
            "insurance_fee":      self.insurance_fee,
            "has_accident":       self.has_accident,
            "has_outer_damage":   self.has_outer_damage,
            "has_flood":          self.has_flood,
            "has_fire":           self.has_fire,
            "has_tuning":         self.has_tuning,
            "accident_detail":    self.accident_detail,
            "outer_detail":       self.outer_detail,
            "details":            details_json,
        }
