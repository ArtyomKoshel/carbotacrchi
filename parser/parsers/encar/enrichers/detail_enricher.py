"""Encar detail data enricher."""

from __future__ import annotations

import logging
import re as _re

from models import CarLot
from ..field_mapper import FieldMapper, create_encar_detail_mappings, create_encar_contact_mappings
from ..constants import SOURCE

logger = logging.getLogger(__name__)


class DetailEnricher:
    """Handles enrichment from Encar detail API data."""
    
    def __init__(self, normalizer):
        self.normalizer = normalizer
        self.spec_mapper = create_encar_detail_mappings(normalizer)
        self.contact_mapper = create_encar_contact_mappings()
    
    def enrich(self, lot: CarLot, detail: dict) -> None:
        """Enrich CarLot with detail API data."""
        # Extract nested sections
        spec = detail.get("spec", {})
        contact = detail.get("contact", {})
        manage = detail.get("manage", {})
        photos = detail.get("photos", [])
        opts = detail.get("options", {})
        partner = detail.get("partnership", {})
        
        # Apply spec mappings
        self.spec_mapper.apply(spec, lot)
        
        # Apply contact mappings
        self.contact_mapper.apply(contact, lot)
        
        # Handle dealer company from partner data
        dealer = (partner or {}).get("dealer") or {}
        firm = dealer.get("firm") or {}
        if firm.get("name"):
            lot.dealer_company = firm["name"]
        
        # Registration date
        if manage.get("registDateTime"):
            lot.registration_date = manage["registDateTime"][:10]
        
        # Photo handling
        self._enrich_photos(lot, photos)
        
        # Options
        std_opts = opts.get("standard", [])
        if std_opts:
            lot.options = std_opts
        
        # Store additional raw data
        self._store_raw_data(lot, detail, spec, contact, manage, photos)
    
    def _enrich_photos(self, lot: CarLot, photos: list) -> None:
        """Enrich photo URLs and extract inspection vehicle ID."""
        if not photos:
            return
        
        # Outer photo for main image
        outer = [p["path"] for p in photos if p.get("type") == "OUTER"]
        if outer and not lot.image_url:
            from .client import EncarClient
            lot.image_url = EncarClient.photo_url(outer[0])
        
        # All photo URLs
        from .client import EncarClient
        all_photo_urls = [EncarClient.photo_url(p["path"]) for p in photos if p.get("path")]
        if all_photo_urls:
            lot.raw_data["photos"] = all_photo_urls
        
        # Extract inspection vehicle ID from photo paths
        # Pattern: /pic4097/40977911_004.jpg where 40977911 differs from listing ID
        first_photo = photos[0] if photos else {}
        path = first_photo.get("path", "")
        if path:
            match = _re.search(r'/(\d+)_\d+\.', path)
            if match and match.group(1) != lot.id:
                lot.raw_data["inspect_vehicle_id"] = match.group(1)
    
    def _store_raw_data(self, lot: CarLot, detail: dict, spec: dict, contact: dict, manage: dict, photos: list) -> None:
        """Store additional raw data for reference."""
        # Store seat count if present
        if spec.get("seatCount"):
            lot.raw_data["seat_count"] = spec["seatCount"]
        
        # Store category data
        category = detail.get("category", {})
        if category:
            lot.raw_data["category"] = category
        
        # Store condition data
        condition = detail.get("condition", [])
        if condition:
            lot.raw_data["condition"] = condition
