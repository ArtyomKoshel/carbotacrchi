"""Human-readable catalogue of how each CarLot field is populated.

This is a DOCUMENTATION module — not executable mapping logic. It lists
every CarLot attribute and shows, for each parser source, which raw API
field / HTML element / normalization function it comes from, plus the
destination DB column in `lots`.

Use this when:
  - onboarding a new parser (see the pattern)
  - debugging "why is field X empty for lots from source Y"
  - planning a schema change (which parsers would need updates)

To keep it accurate, every time you add a new field to CarLot or change
how a parser populates an existing field, update the corresponding entry
here. If the entry is stale, the field-coverage report will reveal it.

Machine-readable form (schema_dict) is available via `parsers/fields/registry.py`.
This module is the prose companion.
"""

from __future__ import annotations

from dataclasses import dataclass, field as _field


@dataclass(frozen=True)
class SourceExtraction:
    """How one parser extracts a single value."""
    raw_location: str           # Where to look in the source (JSON path, Korean label, etc.)
    transform: str = "copy"     # Name of normalization fn / transform pipeline
    notes: str = ""             # Optional clarification


@dataclass(frozen=True)
class FieldMapping:
    """Mapping of a CarLot attribute → DB column → per-source extraction."""
    attribute: str              # CarLot attribute name (dataclass field)
    db_column: str              # Column in the `lots` table
    extractions: dict[str, SourceExtraction] = _field(default_factory=dict)
    notes: str = ""


# ── Hardcoded catalogue ─────────────────────────────────────────────────────
FIELD_MAPPINGS: list[FieldMapping] = [
    # ── Identity ────────────────────────────────────────────────────────────
    FieldMapping(
        attribute="id", db_column="id",
        extractions={
            "encar": SourceExtraction("Id (search/list API)", "str(item['Id'])"),
            "kbcha": SourceExtraction("carSeq (list page HTML)", "f'kbcha_{carSeq}'"),
        },
        notes="Must be unique per source. ID namespace prevents collisions across sources.",
    ),
    FieldMapping(
        attribute="source", db_column="source",
        extractions={
            "encar": SourceExtraction("const 'encar'"),
            "kbcha": SourceExtraction("const 'kbcha'"),
        },
    ),
    FieldMapping(
        attribute="make", db_column="make",
        extractions={
            "encar": SourceExtraction("Manufacturer",          "EncarNormalizer.make() via ENCAR_MAKE"),
            "kbcha": SourceExtraction("maker_code OR title",   "KBChaNormalizer.normalize_make() via KBCHA_MAKE / KBCHA_MAKER_CODE"),
        },
    ),
    FieldMapping(
        attribute="model", db_column="model",
        extractions={
            "encar": SourceExtraction("Model + Badge",         "f'{Model} {Badge}'.strip()"),
            "kbcha": SourceExtraction("title (HTML)",          "KBChaNormalizer.parse_title().model"),
        },
    ),
    FieldMapping(
        attribute="trim", db_column="trim",
        extractions={
            "encar": SourceExtraction("BadgeDetail",           "copy"),
            "kbcha": SourceExtraction("title trim-tokens",     "KBChaNormalizer.parse_title().trim"),
        },
    ),
    FieldMapping(
        attribute="year", db_column="year",
        extractions={
            "encar": SourceExtraction("FormYear or Year",      "int(str(val)[:4])"),
            "kbcha": SourceExtraction("연식 field in detail",    "KBChaNormalizer.parse_year()"),
        },
    ),
    FieldMapping(
        attribute="vin", db_column="vin",
        extractions={
            "encar": SourceExtraction("detail.vin / inspection.vin", "copy (non-overwrite)"),
            "kbcha": SourceExtraction("차대번호 (inspection report)",  "copy"),
        },
        notes="Most KBCha lots lack VIN until the external inspection report is parsed.",
    ),
    FieldMapping(
        attribute="plate_number", db_column="plate_number",
        extractions={
            "encar": SourceExtraction("detail.vehicleNo",       "copy"),
            "kbcha": SourceExtraction("차량번호 (basic-info popup)", "copy"),
        },
    ),
    FieldMapping(
        attribute="registration_date", db_column="registration_date",
        extractions={
            "encar": SourceExtraction("manage.registDateTime[:10] or record.firstDate", "ISO date"),
            "kbcha": SourceExtraction("연식 reg portion",        "date parsing"),
        },
    ),
    FieldMapping(
        attribute="registration_year_month", db_column="registration_year_month",
        extractions={
            "encar": SourceExtraction("FormYear (already YYYYMM)", "int()"),
            "kbcha": SourceExtraction("연식 year_text",              "parse_year_month() → YYYYMM"),
        },
        notes="Compact int (YYYYMM, e.g. 202006). Easy to filter and index.",
    ),

    # ── Price ───────────────────────────────────────────────────────────────
    FieldMapping(
        attribute="price", db_column="price",
        extractions={
            "encar": SourceExtraction("Price (만원 unit)",        "int * 10000 (KRW)"),
            "kbcha": SourceExtraction("displayed price (만원)",   "parse_price_man * 10000"),
        },
        notes="Canonical price in KRW. No USD/other-unit duplicate columns.",
    ),
    FieldMapping(
        attribute="retail_value", db_column="retail_value",
        extractions={
            "encar": SourceExtraction("category.originPrice (만원)", "int * 10000 (KRW)"),
            "kbcha": SourceExtraction("detail table retail cell",   "int(text)"),
        },
        notes="MSRP (new-car price) in KRW. Unified across parsers.",
    ),
    FieldMapping(
        attribute="repair_cost", db_column="repair_cost",
        extractions={
            "encar": SourceExtraction("record.myAccidentCost + otherAccidentCost", "sum"),
        },
    ),
    FieldMapping(
        attribute="new_car_price_ratio", db_column="new_car_price_ratio",
        extractions={
            "kbcha": SourceExtraction("신차 대비 X% regex in detail", "int(match.group(1))"),
        },
    ),

    # ── Odometer / age ──────────────────────────────────────────────────────
    FieldMapping(
        attribute="mileage", db_column="mileage",
        extractions={
            "encar": SourceExtraction("Mileage",               "int"),
            "kbcha": SourceExtraction("주행거리 cell",            "KBChaNormalizer.parse_mileage()"),
        },
    ),
    FieldMapping(
        attribute="mileage_grade", db_column="mileage_grade",
        extractions={
            "kbcha": SourceExtraction("mileage-grade regex",   "MILEAGE_GRADE_PATTERN"),
        },
    ),

    # ── Technical specs (unified via BaseNormalizer) ───────────────────────
    FieldMapping(
        attribute="fuel", db_column="fuel",
        extractions={
            "encar": SourceExtraction("FuelType",              "EncarNormalizer.fuel() → FUEL_* const"),
            "kbcha": SourceExtraction("연료 cell",               "KBChaNormalizer.normalize_fuel() → FUEL_* const"),
        },
        notes="Canonical lowercase FUEL_* from vocabulary.py.",
    ),
    FieldMapping(
        attribute="transmission", db_column="transmission",
        extractions={
            "encar": SourceExtraction("Transmission",          "EncarNormalizer.transmission() → TRANS_*"),
            "kbcha": SourceExtraction("변속기 cell",              "KBChaNormalizer.normalize_transmission() → TRANS_*"),
        },
    ),
    FieldMapping(
        attribute="body_type", db_column="body_type",
        extractions={
            "encar": SourceExtraction("spec.bodyName",         "EncarNormalizer.body() → BODY_*"),
            "kbcha": SourceExtraction("차종 cell",               "KBChaNormalizer.normalize_body_type() → BODY_*"),
        },
    ),
    FieldMapping(
        attribute="drive_type", db_column="drive_type",
        extractions={
            "encar": SourceExtraction("spec.drivingMethodName / badge tokens", "EncarNormalizer.drive() → DRIVE_*"),
            "kbcha": SourceExtraction("구동 cell / title tokens", "KBChaNormalizer.normalize_drive_type() → DRIVE_*"),
        },
    ),
    FieldMapping(
        attribute="engine_volume", db_column="engine_volume",
        extractions={
            "encar": SourceExtraction("spec.displacement",     "round(cc / 1000, 1) — liters"),
            "kbcha": SourceExtraction("배기량 cell (cc)",         "KBChaNormalizer.parse_engine_cc() — liters"),
        },
    ),
    FieldMapping(
        attribute="fuel_economy", db_column="fuel_economy",
        extractions={
            "kbcha": SourceExtraction("연비 cell",               "KBChaNormalizer.parse_fuel_economy()"),
        },
    ),
    FieldMapping(
        attribute="cylinders", db_column="cylinders",
        extractions={
            "kbcha": SourceExtraction("engine_str (parsed)",   "KBChaNormalizer.parse_cylinders()"),
        },
    ),
    FieldMapping(
        attribute="color", db_column="color",
        extractions={
            "encar": SourceExtraction("Color / spec.colorName", "EncarNormalizer.color()"),
            "kbcha": SourceExtraction("차량색상 cell",            "KBChaNormalizer.normalize_color()"),
        },
    ),
    FieldMapping(
        attribute="seat_color", db_column="seat_color",
        extractions={
            "encar": SourceExtraction("SeatColor",             "copy"),
            "kbcha": SourceExtraction("시트색상 cell",            "KBChaNormalizer.normalize_color()"),
        },
    ),

    # ── Sales model (added in P1) ───────────────────────────────────────────
    FieldMapping(
        attribute="sell_type", db_column="sell_type",
        extractions={
            "encar": SourceExtraction(
                "SellType + AdType + Condition[]",
                "_shared.sell_type.normalize_encar()",
            ),
            "kbcha": SourceExtraction(
                "inspection.usage_change",
                "_shared.sell_type.normalize_kbcha_usage()",
                notes="Only filled after external inspection report parsing."
            ),
        },
        notes="Used by FilterEngine (rental/lease/under_contract exclusion).",
    ),
    FieldMapping(
        attribute="sell_type_raw", db_column="sell_type_raw",
        extractions={
            "encar": SourceExtraction("pipe-joined raw values", "debug string"),
            "kbcha": SourceExtraction("original usage_change",  "debug string"),
        },
    ),

    # ── Location & links ────────────────────────────────────────────────────
    FieldMapping(
        attribute="location", db_column="location",
        extractions={
            "encar": SourceExtraction("OfficeCityState or contact.address", "copy"),
            "kbcha": SourceExtraction("dealer location cell",  "copy"),
        },
    ),
    FieldMapping(
        attribute="lot_url", db_column="lot_url",
        extractions={
            "encar": SourceExtraction("f'https://fem.encar.com/cars/detail/{id}'", "templated"),
            "kbcha": SourceExtraction("f'https://www.kbchachacha.com/public/car/detail.kbc?carSeq={id}'", "templated"),
        },
    ),
    FieldMapping(
        attribute="image_url", db_column="image_url",
        extractions={
            "encar": SourceExtraction("Photos[0].location",    "EncarClient.photo_url()"),
            "kbcha": SourceExtraction("image tag in detail",   "absolute URL"),
        },
    ),

    # ── Legal status ────────────────────────────────────────────────────────
    FieldMapping(
        attribute="lien_status", db_column="lien_status",
        extractions={
            "encar": SourceExtraction("record.loan",            "'clean' | 'lien'"),
            "kbcha": SourceExtraction("압류 cell",                "copy (Korean text)"),
        },
    ),
    FieldMapping(
        attribute="seizure_status", db_column="seizure_status",
        extractions={
            "encar": SourceExtraction("record.robberCnt",       "'clean' | 'seizure'"),
            "kbcha": SourceExtraction("저당 cell",                "copy (Korean text)"),
        },
    ),
    FieldMapping(
        attribute="tax_paid", db_column="tax_paid",
        extractions={
            "kbcha": SourceExtraction("세금미납 cell",            "bool('없음')"),
        },
    ),

    # ── Condition & accident history ────────────────────────────────────────
    FieldMapping(
        attribute="damage", db_column="damage",
        extractions={
            "kbcha": SourceExtraction("structural panels in report", "comma-joined panel names"),
        },
    ),
    FieldMapping(
        attribute="secondary_damage", db_column="secondary_damage",
        extractions={
            "kbcha": SourceExtraction("outer panels in report",  "comma-joined"),
        },
    ),
    FieldMapping(
        attribute="has_accident", db_column="has_accident",
        extractions={
            "encar": SourceExtraction("myAccidentCnt + otherAccidentCnt", "sum > 0"),
            "kbcha": SourceExtraction("사고이력 inspection",       "bool"),
        },
    ),
    FieldMapping(
        attribute="flood_history", db_column="flood_history",
        extractions={
            "encar": SourceExtraction("floodTotalLossCnt + floodPartLossCnt", "sum > 0"),
            "kbcha": SourceExtraction("침수 inspection flag",    "bool"),
        },
    ),
    FieldMapping(
        attribute="total_loss_history", db_column="total_loss_history",
        extractions={
            "encar": SourceExtraction("record.totalLossCnt",    "int > 0"),
        },
    ),
    FieldMapping(
        attribute="owners_count", db_column="owners_count",
        extractions={
            "encar": SourceExtraction("record.ownerChangeCnt",  "int"),
            "kbcha": SourceExtraction("소유자변경 cell",           "regex int"),
        },
    ),
    FieldMapping(
        attribute="insurance_count", db_column="insurance_count",
        extractions={
            "encar": SourceExtraction("record.accidentCnt",     "int"),
        },
    ),
    FieldMapping(
        attribute="has_keys", db_column="has_keys",
        extractions={
            "encar": SourceExtraction("verification option 10", "int > 0"),
        },
    ),

    # ── Options ─────────────────────────────────────────────────────────────
    FieldMapping(
        attribute="options", db_column="options",
        extractions={
            "encar": SourceExtraction("options.standard[]",      "json array"),
            "kbcha": SourceExtraction("detail option list",      "json array"),
        },
    ),
    FieldMapping(
        attribute="paid_options", db_column="paid_options",
        extractions={
            "kbcha": SourceExtraction("paid options section",    "regex + json array"),
        },
    ),
    FieldMapping(
        attribute="warranty_text", db_column="warranty_text",
        extractions={
            "kbcha": SourceExtraction("warranty regex",          "WARRANTY_PATTERN match"),
        },
    ),

    # ── Dealer info ─────────────────────────────────────────────────────────
    FieldMapping(
        attribute="dealer_name", db_column="dealer_name",
        extractions={
            "encar": SourceExtraction("contact.userId",          "copy"),
            "kbcha": SourceExtraction("dealer cell",             "copy"),
        },
    ),
    FieldMapping(
        attribute="dealer_company", db_column="dealer_company",
        extractions={
            "encar": SourceExtraction("partnership.dealer.firm.name", "copy"),
            "kbcha": SourceExtraction("dealer company cell",     "copy"),
        },
    ),
    FieldMapping(
        attribute="dealer_phone", db_column="dealer_phone",
        extractions={
            "encar": SourceExtraction("contact.no",              "copy"),
        },
    ),

    # ── Opaque blob ─────────────────────────────────────────────────────────
    FieldMapping(
        attribute="raw_data", db_column="raw_data",
        extractions={
            "encar": SourceExtraction("extras from detail + record + inspection + diagnosis", "JSON blob"),
            "kbcha": SourceExtraction("extras from detail + inspection", "JSON blob"),
        },
        notes=(
            "Free-form parser-specific data (photos, conditions, engine_code, "
            "inspection_type, ai_price_*, etc). Searchable JSON."
        ),
    ),
]


# ── Lookup helpers ──────────────────────────────────────────────────────────
FIELD_MAPPINGS_BY_ATTR: dict[str, FieldMapping] = {m.attribute: m for m in FIELD_MAPPINGS}


def get_mapping(attribute: str) -> FieldMapping | None:
    """Return the FieldMapping describing a CarLot attribute."""
    return FIELD_MAPPINGS_BY_ATTR.get(attribute)


def mappings_for_source(source: str) -> list[FieldMapping]:
    """Return every mapping that has an extraction for the given parser source."""
    return [m for m in FIELD_MAPPINGS if source in m.extractions]


def render_markdown_catalogue() -> str:
    """Return a pretty markdown table of all mappings — handy for `docs/`."""
    lines: list[str] = [
        "# Field mapping catalogue",
        "",
        "Every column in `lots` table and which parser populates it.",
        "",
        "| Attribute | DB column | Source | Extraction | Transform |",
        "|-----------|-----------|--------|------------|-----------|",
    ]
    for m in FIELD_MAPPINGS:
        if not m.extractions:
            lines.append(f"| `{m.attribute}` | `{m.db_column}` | — | not populated | — |")
            continue
        for src, ext in m.extractions.items():
            lines.append(
                f"| `{m.attribute}` | `{m.db_column}` | `{src}` | "
                f"{ext.raw_location} | `{ext.transform}` |"
            )
        if m.notes:
            lines.append(f"| | | | *{m.notes}* | |")
    return "\n".join(lines)


# ── JSON schema export (consumed by Laravel admin UI) ──────────────────────
def _mapping_to_dict(m: FieldMapping) -> dict:
    """Serialize one FieldMapping into a JSON-friendly dict."""
    from fields import get_field
    spec = get_field(m.attribute)
    return {
        "attribute":   m.attribute,
        "db_column":   m.db_column,
        "dtype":       spec.dtype.value if spec else None,
        "category":    spec.category if spec else "other",
        "filterable":  bool(spec.filterable) if spec else False,
        "notes":       m.notes or "",
        "extractions": [
            {
                "source":       src,
                "raw_location": ext.raw_location,
                "transform":    ext.transform,
                "notes":        ext.notes,
            }
            for src, ext in m.extractions.items()
        ],
    }


def schema_dict() -> dict:
    """Full catalogue as a dict ready for JSON serialization."""
    return {
        "version":  1,
        "mappings": [_mapping_to_dict(m) for m in FIELD_MAPPINGS],
    }


def schema_json(indent: int | None = 2) -> str:
    """Catalogue as a JSON string — used by Laravel admin / exports."""
    import json
    return json.dumps(schema_dict(), ensure_ascii=False, indent=indent, default=str)


if __name__ == "__main__":
    # Allow `python -m parsers._shared.field_mappings > docs/fields.md`
    import sys
    if "--json" in sys.argv:
        print(schema_json())
    else:
        print(render_markdown_catalogue())
