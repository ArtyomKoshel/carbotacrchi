"""Field mapper for unified dictionary processing across parsers."""

from __future__ import annotations

from typing import Any, Callable, Dict, Tuple

from models import CarLot


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
    
    def apply(self, source: Dict[str, Any], target: CarLot) -> bool:
        """Apply this mapping if conditions are met."""
        value = source.get(self.source_key)
        if value is None or not self.condition(value):
            return False
        
        # Don't overwrite existing values unless explicitly allowed
        if not self.overwrite and hasattr(target, self.target_field):
            current_value = getattr(target, self.target_field)
            if current_value is not None:
                return False
        
        try:
            transformed_value = self.transformer(value)
            setattr(target, self.target_field, transformed_value)
            return True
        except Exception:
            # Silently fail on transformation errors
            return False


class FieldMapper:
    """Maps multiple fields from source dict to target object."""
    
    def __init__(self, mappings: Dict[str, FieldMapping] | None = None):
        self.mappings = mappings or {}
    
    def add_mapping(self, mapping: FieldMapping) -> 'FieldMapper':
        """Add a field mapping."""
        self.mappings[mapping.source_key] = mapping
        return self
    
    def apply(self, source: Dict[str, Any], target: CarLot) -> int:
        """Apply all mappings and return count of successful mappings."""
        applied = 0
        for mapping in self.mappings.values():
            if mapping.apply(source, target):
                applied += 1
        return applied
    
    def apply_raw_data(self, source: Dict[str, Any], target: CarLot) -> None:
        """Apply mappings to raw_data dict."""
        for mapping in self.mappings.values():
            value = source.get(mapping.source_key)
            if value is not None and mapping.condition(value):
                try:
                    transformed_value = mapping.transformer(value)
                    target.raw_data[mapping.target_field] = transformed_value
                except Exception:
                    pass


# Common field mappings for Encar
def create_encar_detail_mappings(normalizer) -> FieldMapper:
    """Create standard field mappings for Encar detail data."""
    mapper = FieldMapper()
    
    # Spec mappings
    mapper.add_mapping(FieldMapping(
        "transmissionName", "transmission", normalizer.transmission
    ))
    mapper.add_mapping(FieldMapping(
        "fuelName", "fuel", normalizer.fuel
    ))
    mapper.add_mapping(FieldMapping(
        "colorName", "color", lambda x: x
    ))
    mapper.add_mapping(FieldMapping(
        "bodyName", "body_type", normalizer.body
    ))
    mapper.add_mapping(FieldMapping(
        "displacement", "engine_volume", lambda x: round(x / 1000, 1) if x else None
    ))
    mapper.add_mapping(FieldMapping(
        "drivingMethodName", "drive_type", normalizer.drive,
        condition=lambda x: x and not getattr(CarLot, 'drive_type', None)
    ))
    mapper.add_mapping(FieldMapping(
        "seatCount", "seat_count", lambda x: x, overwrite=False
    ))
    
    # Basic info mappings
    mapper.add_mapping(FieldMapping(
        "vin", "vin", lambda x: x
    ))
    mapper.add_mapping(FieldMapping(
        "vehicleNo", "plate_number", lambda x: x
    ))
    
    return mapper


def create_encar_contact_mappings() -> FieldMapper:
    """Create contact/dealer field mappings."""
    mapper = FieldMapper()
    
    mapper.add_mapping(FieldMapping(
        "address", "location", lambda x: x
    ))
    mapper.add_mapping(FieldMapping(
        "no", "dealer_phone", lambda x: x
    ))
    mapper.add_mapping(FieldMapping(
        "userId", "dealer_name", lambda x: x
    ))
    
    return mapper
