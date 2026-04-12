"""Encar record data enricher."""

from __future__ import annotations

import logging

from models import CarLot, InspectionRecord
from ..constants import SOURCE

logger = logging.getLogger(__name__)


class RecordEnricher:
    """Handles enrichment from Encar record API data."""
    
    def enrich(self, lot: CarLot, rec: dict) -> InspectionRecord:
        """Enrich CarLot and create InspectionRecord from record API data."""
        # Extract accident counts
        my_cnt = int(rec.get("myAccidentCnt") or 0)
        other_cnt = int(rec.get("otherAccidentCnt") or 0)
        total_cnt = my_cnt + other_cnt
        
        # Create inspection record
        record = InspectionRecord(
            lot_id=lot.id,
            source=SOURCE,
            has_accident=total_cnt > 0,
            my_accident_cnt=my_cnt,
            other_accident_cnt=other_cnt,
        )
        
        # Extract accident details
        accidents = rec.get("accidents") or []
        if accidents:
            record.accident_history = [
                {
                    "date": acc.get("accidentDate"),
                    "type": acc.get("accidentTypeName"),
                    "cost": acc.get("repairCost", 0),
                    "location": acc.get("accidentLocation"),
                }
                for acc in accidents
            ]
        
        # Extract repair costs
        my_cost = int(rec.get("myAccidentCost") or 0)
        other_cost = int(rec.get("otherAccidentCost") or 0)
        if my_cost + other_cost > 0:
            lot.repair_cost = my_cost + other_cost
            record.repair_cost = my_cost + other_cost
        
        # Registration date fallback
        if rec.get("firstDate") and not lot.registration_date:
            lot.registration_date = rec["firstDate"]
            record.first_registration = rec["firstDate"]
        
        # Lien and seizure status
        if rec.get("loan") is not None:
            lot.lien_status = "has_loan" if rec["loan"] else "clear"
            record.has_lien = rec["loan"]
        
        if rec.get("robberCnt") is not None:
            seizure_cnt = int(rec["robberCnt"])
            lot.seizure_status = "seized" if seizure_cnt > 0 else "clear"
            record.seizure_count = seizure_cnt
        
        # Store raw record data
        record.raw_data = {
            "openData": rec.get("openData", {}),
            "vehicleId": rec.get("vehicleId"),
        }
        
        return record
