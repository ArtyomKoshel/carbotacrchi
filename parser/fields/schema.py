"""Serializable schema of the FieldRegistry for consumption by Laravel / JS UI.

The Laravel admin panel can fetch this JSON to render the filter-rule form:
  - a dropdown of fields grouped by category
  - per-field operator menu derived from dtype
  - enum dropdowns where applicable
"""

from __future__ import annotations

import json
from typing import Any

from .registry import FIELDS, FieldSpec


def _spec_to_dict(spec: FieldSpec) -> dict[str, Any]:
    return {
        "name": spec.name,
        "column": spec.column,
        "dtype": spec.dtype.value,
        "category": spec.category,
        "description": spec.description,
        "required": spec.required,
        "filterable": spec.filterable,
        "tracked": spec.tracked,
        "enum_values": list(spec.enum_values) if spec.enum_values else None,
        "operators": list(spec.allowed_operators),
        "sources": dict(spec.sources),
    }


def schema_dict() -> dict[str, Any]:
    """Return the full registry as a dict ready for JSON serialization."""
    return {
        "version": 1,
        "fields": [_spec_to_dict(f) for f in FIELDS],
    }


def schema_json(indent: int | None = 2) -> str:
    """Return the registry schema as a formatted JSON string."""
    return json.dumps(schema_dict(), ensure_ascii=False, indent=indent, default=str)


if __name__ == "__main__":
    # Allow `python -m fields.schema > fields.json` for quick export.
    print(schema_json())
