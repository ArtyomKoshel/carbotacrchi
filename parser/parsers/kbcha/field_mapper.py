"""Field mapper for KBCha parser with unified dictionary processing."""

from __future__ import annotations

import re
from typing import Any, Callable, Dict

from models import CarLot
from .glossary import INFO_FIELDS


class FieldMapping:
    """Defines how to map a source field to a target field."""
    
    def __init__(
        self,
        source_key: str,
        target_field: str,
        transformer: Callable[[Any], Any] | None = None,
        condition: Callable[[Any], bool] | None = None,
        overwrite: bool = True
    ):
        self.source_key = source_key
        self.target_field = target_field
        self.transformer = transformer or (lambda x: x)
        self.condition = condition or (lambda x: True)
        self.overwrite = overwrite
    
    def apply(self, source: Dict[str, Any], target: CarLot, normalizer=None) -> bool:
        """Apply this mapping if conditions are met."""
        value = source.get(self.source_key)
        if value is None or value == "information" or not self.condition(value):
            return False
        
        # Don't overwrite existing values unless explicitly allowed
        if not self.overwrite and hasattr(target, self.target_field):
            current_value = getattr(target, self.target_field)
            if current_value is not None:
                return False
        
        # Skip private fields starting with _
        if self.target_field.startswith("_"):
            return False
        
        # Special handling for VIN to avoid duplicates
        if self.target_field == "vin" and hasattr(target, 'vin') and target.vin:
            return False
        
        try:
            transformed_value = self.transformer(value)
            setattr(target, self.target_field, transformed_value)
            return True
        except Exception:
            return False


class KBChaFieldMapper:
    """Field mapper specialized for KBCha data processing."""
    
    def __init__(self, normalizer):
        self.normalizer = normalizer
        self.mappings = self._create_mappings()
    
    def _create_mappings(self) -> Dict[str, FieldMapping]:
        """Create field mappings based on INFO_FIELDS from glossary."""
        mappings = {}
        
        for kr_key, (field_name, method) in INFO_FIELDS.items():
            if method is None:
                # Direct mapping
                mappings[kr_key] = FieldMapping(
                    kr_key, field_name, 
                    condition=lambda x: x and x != "information"
                )
            elif method == "_parse_year":
                mappings[kr_key] = FieldMapping(
                    kr_key, "year", 
                    transformer=lambda x, norm=self.normalizer: norm.parse_year(x),
                    condition=lambda x: x and x != "information"
                )
                # Also store registration_year_month (YYYYMM int)
                mappings[kr_key + "_ym"] = FieldMapping(
                    kr_key, "registration_year_month",
                    transformer=lambda x, norm=self.normalizer: norm.parse_year_month(x),
                    condition=lambda x: x and x != "information",
                    overwrite=False
                )
            elif method == "_parse_mileage":
                mappings[kr_key] = FieldMapping(
                    kr_key, "mileage",
                    transformer=lambda x, norm=self.normalizer: norm.parse_mileage(x),
                    condition=lambda x: x and x != "information"
                )
            elif method == "_parse_owners":
                mappings[kr_key] = FieldMapping(
                    kr_key, "owners_count",
                    transformer=lambda x: int(re.search(r"(\d+)", x).group(1)) if re.search(r"(\d+)", x) else None,
                    condition=lambda x: x and x != "information"
                )
            elif hasattr(self.normalizer, method):
                # Use normalizer method
                mappings[kr_key] = FieldMapping(
                    kr_key, field_name,
                    transformer=lambda x, m=method, norm=self.normalizer: getattr(norm, m)(x),
                    condition=lambda x: x and x != "information"
                )
        
        return mappings
    
    def apply(self, source: Dict[str, Any], target: Dict[str, Any]) -> int:
        """Apply all mappings and return count of successful mappings."""
        applied = 0
        for mapping in self.mappings.values():
            if self._apply_mapping(mapping, source, target):
                applied += 1
        return applied
    
    def _apply_mapping(self, mapping: 'FieldMapping', source: Dict[str, Any], target: Dict[str, Any]) -> bool:
        """Apply a single mapping to dict target."""
        value = source.get(mapping.source_key)
        if value is None or value == "information" or not mapping.condition(value):
            return False
        
        # Don't overwrite existing values unless explicitly allowed
        if not mapping.overwrite and mapping.target_field in target:
            current_value = target[mapping.target_field]
            if current_value is not None:
                return False
        
        # Apply transformation
        try:
            transformed_value = mapping.transformer(value)
            if transformed_value is not None:
                target[mapping.target_field] = transformed_value
                return True
        except Exception:
            pass
        return False
    
    def apply_raw_data(self, source: Dict[str, Any], target: Dict[str, Any]) -> None:
        """Store raw info data."""
        target["_raw_info"] = dict(source)

        # Parse cylinders from engine_str in target or 배기량 raw value in source
        engine_str = target.get("engine_str") or source.get("배기량") or ""
        if engine_str:
            cylinders = self.normalizer.parse_cylinders(engine_str)
            if cylinders:
                target["cylinders"] = cylinders


def create_kbcha_history_mappings() -> Dict[str, FieldMapping]:
    """Create mappings for history section data."""
    from .glossary import HISTORY_BOOL_LABELS
    
    mappings = {}
    for kr_key, field_name in HISTORY_BOOL_LABELS.items():
        mappings[kr_key] = FieldMapping(
            kr_key, field_name,
            transformer=lambda x: x != "none",
            condition=lambda x: x is not None
        )
    
    return mappings
