"""Unified Field Registry — SSOT for every field that flows into the lots table.

The registry documents, for each field:
  - the Python attribute name on CarLot
  - the DB column name (if different)
  - the Python type
  - which parsers populate it and from which source field
  - whether it is user-filterable (available in FilterEngine rule forms)
  - whether it participates in change-detection (lot_changes history)
  - a short human-readable description

Use cases:
  - Admin UI dropdowns for Filter Engine rule creation
  - Automatic SQL row generation (to_db_row via registry)
  - Field-coverage reporting: "VIN is populated for 40% of Encar lots, 15% of KBCha"
  - Documentation — single place to discover what data flows in
"""

from .registry import (
    FieldSpec,
    FieldType,
    FIELDS,
    FIELDS_BY_NAME,
    FILTERABLE_FIELDS,
    TRACKED_FIELDS,
    get_field,
    list_filterable,
    list_by_source,
)

__all__ = [
    "FieldSpec",
    "FieldType",
    "FIELDS",
    "FIELDS_BY_NAME",
    "FILTERABLE_FIELDS",
    "TRACKED_FIELDS",
    "get_field",
    "list_filterable",
    "list_by_source",
]
