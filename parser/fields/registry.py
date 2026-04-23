"""Field registry — declarative catalogue of every CarLot field."""

from __future__ import annotations

from dataclasses import dataclass, field
from enum import Enum


class FieldType(str, Enum):
    """Simplified type classification used by the filter UI and validators."""

    STRING = "string"       # VARCHAR
    TEXT = "text"           # TEXT / long string
    INT = "int"
    FLOAT = "float"
    BOOL = "bool"
    DATE = "date"           # ISO 'YYYY-MM-DD'
    DATETIME = "datetime"   # ISO 'YYYY-MM-DD HH:MM:SS'
    ENUM = "enum"           # string constrained to a set of values
    JSON = "json"           # list / dict serialized


@dataclass(frozen=True)
class FieldSpec:
    """Declarative specification of a single CarLot field.

    Attributes:
        name:         Python attribute on CarLot
        db_column:    column name in `lots` table (defaults to name)
        dtype:        high-level type for UI / validation
        required:     True if value should always be present (e.g. id, source)
        filterable:   exposed in Filter Engine UI as a field to build rules on
        tracked:      participates in change-detection into `lot_changes`
        enum_values:  for FieldType.ENUM — allowed values (used for UI dropdown)
        operators:    allowed operators in filter rules (defaults inferred from dtype)
        sources:      {parser_source: "description where this value is sourced from"}
        description:  human-friendly label for UI
        category:     grouping in UI: "identity", "specs", "price", "condition", ...
    """

    name: str
    dtype: FieldType
    db_column: str | None = None
    required: bool = False
    filterable: bool = False
    tracked: bool = False
    enum_values: tuple[str, ...] | None = None
    operators: tuple[str, ...] | None = None
    sources: dict = field(default_factory=dict)
    description: str = ""
    category: str = "other"

    @property
    def column(self) -> str:
        return self.db_column or self.name

    @property
    def allowed_operators(self) -> tuple[str, ...]:
        if self.operators is not None:
            return self.operators
        return _DEFAULT_OPERATORS.get(self.dtype, _DEFAULT_OPERATORS[FieldType.STRING])


# Default operator menu per type — what makes sense for UI dropdowns
_DEFAULT_OPERATORS: dict[FieldType, tuple[str, ...]] = {
    FieldType.STRING:   ("eq", "ne", "in", "not_in", "contains", "not_contains", "regex", "is_null", "is_not_null"),
    FieldType.TEXT:     ("contains", "not_contains", "regex", "is_null", "is_not_null"),
    FieldType.INT:      ("eq", "ne", "gt", "gte", "lt", "lte", "between", "in", "not_in", "is_null", "is_not_null"),
    FieldType.FLOAT:    ("eq", "ne", "gt", "gte", "lt", "lte", "between", "is_null", "is_not_null"),
    FieldType.BOOL:     ("eq", "ne", "is_null", "is_not_null"),
    FieldType.DATE:     ("eq", "ne", "gt", "gte", "lt", "lte", "between", "is_null", "is_not_null"),
    FieldType.DATETIME: ("eq", "ne", "gt", "gte", "lt", "lte", "between", "is_null", "is_not_null"),
    FieldType.ENUM:     ("eq", "ne", "in", "not_in", "is_null", "is_not_null"),
    FieldType.JSON:     ("contains", "not_contains", "is_null", "is_not_null"),
}


SELL_TYPE_VALUES: tuple[str, ...] = (
    "sale", "auction", "lease", "rental", "business", "under_contract", "insurance_hide",
)

FUEL_VALUES: tuple[str, ...] = (
    "gasoline", "diesel", "hybrid", "plugin_hybrid", "electric", "lpg", "cng", "hydrogen",
)

TRANSMISSION_VALUES: tuple[str, ...] = (
    "automatic", "manual", "cvt", "dct",
)

BODY_VALUES: tuple[str, ...] = (
    "sedan", "suv", "hatchback", "coupe", "convertible", "pickup",
    "van", "minivan", "wagon", "cargo", "truck", "kei",
)

DRIVE_VALUES: tuple[str, ...] = ("fwd", "rwd", "awd", "4wd", "2wd")


# ── FIELD CATALOGUE ──────────────────────────────────────────────────────────
# Order within this list is the canonical order used by UI and code generators.

FIELDS: list[FieldSpec] = [
    # ── Identity ─────────────────────────────────────────────────────────────
    FieldSpec(
        name="id", dtype=FieldType.STRING, required=True, category="identity",
        description="Primary key: unique lot identifier scoped by source",
        sources={"encar": "Id (from search API)", "kbcha": "carSeq prefixed with kbcha_"},
    ),
    FieldSpec(
        name="source", dtype=FieldType.ENUM, required=True, filterable=True,
        enum_values=("encar", "kbcha"), category="identity",
        description="Origin marketplace",
        sources={"encar": "constant 'encar'", "kbcha": "constant 'kbcha'"},
    ),
    FieldSpec(
        name="make", dtype=FieldType.STRING, required=True, filterable=True, tracked=False,
        category="identity", description="Manufacturer (normalized English name)",
        sources={"encar": "Manufacturer via MAKER_MAP", "kbcha": "makerCode via MAKER_CODE"},
    ),
    FieldSpec(
        name="model", dtype=FieldType.STRING, required=True, filterable=True,
        category="identity", description="Model name (parser-normalized)",
        sources={"encar": "Model + Badge", "kbcha": "classCode + title parsing"},
    ),
    FieldSpec(
        name="trim", dtype=FieldType.STRING, filterable=True, tracked=True,
        category="identity", description="Trim / grade",
        sources={"encar": "BadgeDetail", "kbcha": "title trim-tokens"},
    ),
    FieldSpec(
        name="year", dtype=FieldType.INT, required=True, filterable=True,
        category="identity", description="Model year (4-digit)",
        sources={"encar": "FormYear[:4]", "kbcha": "연식 parsing via parse_year"},
    ),

    # ── Price ────────────────────────────────────────────────────────────────
    FieldSpec(
        name="price", dtype=FieldType.INT, required=True, filterable=True, tracked=True,
        category="price", description="Displayed price (KRW)",
        sources={"encar": "Price * 10000", "kbcha": "price_man * 10000"},
    ),
    FieldSpec(
        name="retail_value", dtype=FieldType.INT, filterable=True, category="price",
        description="MSRP / new-car price in KRW (before depreciation)",
        sources={"encar": "originPrice * 10000", "kbcha": "detail retail cell"},
    ),
    FieldSpec(
        name="repair_cost", dtype=FieldType.INT, filterable=True, category="price",
        description="Total insurance-claim repair cost in KRW",
        sources={"encar": "myAccidentCost + otherAccidentCost"},
    ),
    FieldSpec(
        name="new_car_price_ratio", dtype=FieldType.INT, category="price",
        description="Ratio of current price to MSRP (%)",
        sources={"kbcha": "신차 대비 % text"},
    ),

    # ── Odometer / age ───────────────────────────────────────────────────────
    FieldSpec(
        name="mileage", dtype=FieldType.INT, required=True, filterable=True, tracked=True,
        category="condition", description="Odometer reading (km)",
        sources={"encar": "Mileage", "kbcha": "주행거리 parse_mileage"},
    ),
    FieldSpec(
        name="mileage_grade", dtype=FieldType.ENUM,
        enum_values=("low", "average", "high"),
        category="condition", description="KBCha mileage grade (상 / 중 / 하)",
        sources={"kbcha": "detail table mileage_grade"},
    ),
    FieldSpec(
        name="registration_date", dtype=FieldType.DATE, filterable=True,
        category="identity", description="First registration date",
        sources={"encar": "manage.registDateTime[:10] or record.firstDate",
                 "kbcha": "연식 reg portion"},
    ),
    FieldSpec(
        name="registration_year_month", dtype=FieldType.INT, filterable=True, tracked=True,
        category="identity",
        description="First registration year+month as int YYYYMM (e.g. 202006)",
        sources={"encar": "FormYear (already YYYYMM)",
                 "kbcha": "parse_year_month(연식 cell)"},
    ),

    # ── Technical specs ──────────────────────────────────────────────────────
    FieldSpec(
        name="fuel", dtype=FieldType.ENUM, enum_values=FUEL_VALUES, filterable=True,
        category="specs", description="Fuel type",
        sources={"encar": "FuelType via FUEL_MAP", "kbcha": "연료 via FUEL dict"},
    ),
    FieldSpec(
        name="transmission", dtype=FieldType.ENUM, enum_values=TRANSMISSION_VALUES, filterable=True,
        category="specs", description="Transmission type",
        sources={"encar": "Transmission via TRANSMISSION_MAP", "kbcha": "변속기 via TRANSMISSION"},
    ),
    FieldSpec(
        name="body_type", dtype=FieldType.ENUM, enum_values=BODY_VALUES, filterable=True,
        category="specs", description="Body style",
        sources={"encar": "spec.bodyName via BODY_MAP", "kbcha": "차종"},
    ),
    FieldSpec(
        name="drive_type", dtype=FieldType.ENUM, enum_values=DRIVE_VALUES, filterable=True,
        category="specs", description="Drivetrain",
        sources={"encar": "spec.drivingMethodName / badge tokens",
                 "kbcha": "title drive tokens"},
    ),
    FieldSpec(
        name="engine_volume", dtype=FieldType.FLOAT, filterable=True,
        category="specs", description="Engine displacement (liters)",
        sources={"encar": "spec.displacement / 1000", "kbcha": "배기량 in cc"},
    ),
    FieldSpec(
        name="fuel_economy", dtype=FieldType.FLOAT, category="specs",
        description="Declared fuel economy (km/L)",
        sources={"kbcha": "연비 detail table"},
    ),
    FieldSpec(
        name="cylinders", dtype=FieldType.INT, category="specs",
        description="Number of cylinders",
        sources={"kbcha": "engine_str parse_cylinders"},
    ),
    FieldSpec(
        name="color", dtype=FieldType.STRING, filterable=True, tracked=True,
        category="specs", description="Body color",
        sources={"encar": "Color / spec.colorName", "kbcha": "차량색상"},
    ),
    FieldSpec(
        name="seat_color", dtype=FieldType.STRING, category="specs",
        description="Interior / seat color",
        sources={"encar": "SeatColor", "kbcha": "시트색상"},
    ),

    # ── Location & links ─────────────────────────────────────────────────────
    FieldSpec(
        name="location", dtype=FieldType.STRING, filterable=True,
        category="dealer", description="Dealer location / city",
        sources={"encar": "OfficeCityState or contact.address", "kbcha": "dealer location"},
    ),
    FieldSpec(
        name="lot_url", dtype=FieldType.STRING, required=True, category="identity",
        description="Canonical listing URL",
    ),
    FieldSpec(
        name="image_url", dtype=FieldType.STRING, category="identity",
        description="Primary listing photo",
    ),

    # ── Registration & documents ─────────────────────────────────────────────
    FieldSpec(
        name="vin", dtype=FieldType.STRING, filterable=True,
        category="identity", description="Vehicle Identification Number (17 chars)",
        sources={"encar": "detail.vin / inspection.vin",
                 "kbcha": "external inspection report 차대번호"},
    ),
    FieldSpec(
        name="plate_number", dtype=FieldType.STRING, category="identity",
        description="License plate (Korean format)",
        sources={"encar": "detail.vehicleNo", "kbcha": "차량번호"},
    ),
    FieldSpec(
        name="title", dtype=FieldType.STRING, category="identity",
        description="Title / status label — default 'Clean'",
    ),

    # ── Legal status ─────────────────────────────────────────────────────────
    FieldSpec(
        name="lien_status", dtype=FieldType.ENUM,
        enum_values=("clean", "lien", "has_loan"),
        filterable=True, tracked=True, category="legal",
        description="Lien / loan status (압류)",
        sources={"encar": "record.loan", "kbcha": "detail 압류"},
    ),
    FieldSpec(
        name="seizure_status", dtype=FieldType.ENUM,
        enum_values=("clean", "seizure", "seized"),
        filterable=True, tracked=True, category="legal",
        description="Seizure status (저당)",
        sources={"encar": "record.robberCnt", "kbcha": "detail 저당"},
    ),
    FieldSpec(
        name="tax_paid", dtype=FieldType.BOOL, filterable=True, category="legal",
        description="Whether taxes are paid (세금미납)",
        sources={"kbcha": "detail 세금미납"},
    ),

    # ── Condition & history ──────────────────────────────────────────────────
    FieldSpec(
        name="damage", dtype=FieldType.TEXT, filterable=True, category="condition",
        description="Structural / frame damage description",
        sources={"kbcha": "damaged_panels.structural from report"},
    ),
    FieldSpec(
        name="secondary_damage", dtype=FieldType.TEXT, category="condition",
        description="Non-structural outer damage",
        sources={"kbcha": "damaged_panels.outer from report"},
    ),
    FieldSpec(
        name="has_accident", dtype=FieldType.BOOL, filterable=True, tracked=True,
        category="condition", description="Any accident history on record",
        sources={"encar": "myAccidentCnt + otherAccidentCnt > 0",
                 "kbcha": "report has_accident"},
    ),
    FieldSpec(
        name="flood_history", dtype=FieldType.BOOL, filterable=True, tracked=True,
        category="condition", description="Flood / water damage history",
        sources={"encar": "record floodTotalLossCnt + floodPartLossCnt",
                 "kbcha": "report has_flood"},
    ),
    FieldSpec(
        name="total_loss_history", dtype=FieldType.BOOL, filterable=True, tracked=True,
        category="condition", description="Declared total loss in the past",
        sources={"encar": "record totalLossCnt > 0"},
    ),
    FieldSpec(
        name="owners_count", dtype=FieldType.INT, filterable=True, tracked=True,
        category="condition", description="Number of previous owners",
        sources={"encar": "record.ownerChangeCnt", "kbcha": "detail 소유자변경"},
    ),
    FieldSpec(
        name="insurance_count", dtype=FieldType.INT, filterable=True, tracked=True,
        category="condition", description="Number of insurance claims",
        sources={"encar": "record.accidentCnt"},
    ),
    # ── New first-class columns ──────────────────────────────────────────────
    FieldSpec(
        name="seat_count", dtype=FieldType.INT, category="specs",
        description="Number of seats",
        sources={"encar": "spec.seatCount"},
    ),
    FieldSpec(
        name="is_domestic", dtype=FieldType.BOOL, filterable=True, category="identity",
        description="Domestic (Korean) car",
        sources={"encar": "category.domestic"},
    ),
    FieldSpec(
        name="import_type", dtype=FieldType.STRING, filterable=True, category="identity",
        description="Import classification",
        sources={"encar": "category.importType"},
    ),

    # ── Sales model ──────────────────────────────────────────────────────────
    FieldSpec(
        name="sell_type", dtype=FieldType.ENUM, enum_values=SELL_TYPE_VALUES,
        filterable=True, tracked=True, category="sales",
        description="Normalized sale category (sale/lease/rental/...)",
        sources={"encar": "SellType + AdType + Condition[] via normalize_encar",
                 "kbcha": "usage_change from inspection via normalize_kbcha_usage"},
    ),
    FieldSpec(
        name="sell_type_raw", dtype=FieldType.STRING, category="sales",
        description="Raw source string used to derive sell_type (debug only)",
    ),

    # ── Options ──────────────────────────────────────────────────────────────
    FieldSpec(
        name="options", dtype=FieldType.JSON, filterable=True, tracked=True,
        category="specs", description="Factory option list",
        sources={"encar": "options.standard", "kbcha": "detail option list"},
    ),
    FieldSpec(
        name="paid_options", dtype=FieldType.JSON, category="specs",
        description="Paid / optional extras",
        sources={"kbcha": "detail paid options"},
    ),
    FieldSpec(
        name="warranty_text", dtype=FieldType.STRING, category="condition",
        description="Warranty coverage text",
        sources={"kbcha": "warranty table"},
    ),

    # ── Dealer info ──────────────────────────────────────────────────────────
    FieldSpec(
        name="dealer_name", dtype=FieldType.STRING, filterable=True,
        category="dealer", description="Dealer or seller name",
        sources={"encar": "contact.userId", "kbcha": "detail dealer"},
    ),
    FieldSpec(
        name="dealer_company", dtype=FieldType.STRING, filterable=True,
        category="dealer", description="Dealer company / firm",
        sources={"encar": "partnership.dealer.firm.name", "kbcha": "detail dealer company"},
    ),
    FieldSpec(
        name="dealer_location", dtype=FieldType.STRING, category="dealer",
        description="Dealer geographic location",
    ),
    FieldSpec(
        name="dealer_phone", dtype=FieldType.STRING, category="dealer",
        description="Dealer phone",
    ),
    FieldSpec(
        name="dealer_description", dtype=FieldType.TEXT, category="dealer",
        description="Free-form dealer comment",
    ),

    # ── Opaque blob ──────────────────────────────────────────────────────────
    FieldSpec(
        name="raw_data", dtype=FieldType.JSON, category="meta",
        description="Source-specific extras (photos, conditions, engine_code, etc.)",
    ),
]


# ── Derived lookup tables (computed once at import) ──────────────────────────
FIELDS_BY_NAME: dict[str, FieldSpec] = {f.name: f for f in FIELDS}

FILTERABLE_FIELDS: list[FieldSpec] = [f for f in FIELDS if f.filterable]

TRACKED_FIELDS: list[FieldSpec] = [f for f in FIELDS if f.tracked]


# ── Helpers ──────────────────────────────────────────────────────────────────
def get_field(name: str) -> FieldSpec | None:
    """Return FieldSpec by attribute name, or None if unknown."""
    return FIELDS_BY_NAME.get(name)


def list_filterable() -> list[FieldSpec]:
    """Return fields exposed to the Filter Engine UI."""
    return list(FILTERABLE_FIELDS)


def list_by_source(source: str) -> list[FieldSpec]:
    """Return fields that a specific parser source populates."""
    return [f for f in FIELDS if source in f.sources]
