"""Encar inspection data enricher."""

from __future__ import annotations

import logging

from models import CarLot, InspectionRecord
from ..constants import SOURCE, OUTER_STATUS, _BAD_INNER

logger = logging.getLogger(__name__)


class InspectionEnricher:
    """Handles enrichment from Encar inspection API data."""
    
    def enrich(self, lot: CarLot, insp: dict, record: InspectionRecord) -> None:
        """Enrich CarLot and InspectionRecord with inspection API data."""
        master = insp.get("master", {})
        detail = master.get("detail", {})
        
        # Structural accident (performance inspection judgment)
        if master.get("accdient") is not None:
            if lot.has_accident is None:
                lot.has_accident = master["accdient"]
            record.has_accident = master["accdient"]
        
        # Water damage
        if detail.get("waterlog") is not None:
            lot.flood_history = detail["waterlog"]
            record.has_flood = detail["waterlog"]
        
        # Tuning
        if detail.get("tuning") is not None:
            record.has_tuning = detail["tuning"]
        
        # VIN fallback
        if detail.get("vin") and not lot.vin:
            lot.vin = detail["vin"]
        
        # Outer damage
        self._enrich_outer_damage(lot, insp, record)
        
        # Certification details
        self._enrich_certification(master, record)
        
        # Date parsing
        self._enrich_dates(detail, lot, record)
        
        # Technical details
        self._enrich_technical_details(detail, lot, record)
        
        # Recall status
        self._enrich_recall_status(detail, lot)
        
        # Car state
        self._enrich_car_state(detail, lot)
        
        # Mechanical issues
        self._enrich_mechanical_issues(insp, record)
    
    def _enrich_outer_damage(self, lot: CarLot, insp: dict, record: InspectionRecord) -> None:
        """Enrich outer damage information."""
        outers = insp.get("outers", [])
        if not outers:
            record.has_outer_damage = False
            return
        
        has_damage = False
        damage_parts = []
        
        for outer in outers:
            title = (outer.get("type", {})).get("title", "")
            statuses = [(s.get("title", "")) for s in outer.get("statusTypes", [])]
            
            if title and statuses:
                # Map status codes to descriptions
                status_descs = []
                for status in statuses:
                    status_desc = OUTER_STATUS.get(status, status)
                    status_descs.append(status_desc)
                
                damage_parts.append(f"{title}: {', '.join(status_descs)}")
                has_damage = True
        
        record.has_outer_damage = has_damage
        if damage_parts:
            record.outer_detail = "\n".join(damage_parts)
    
    def _enrich_certification(self, master: dict, record: InspectionRecord) -> None:
        """Enrich certification details."""
        if master.get("supplyNum"):
            record.cert_no = str(master["supplyNum"])[:100]
        
        if master.get("registrationDate"):
            record.inspection_date = master["registrationDate"][:10]
        
        record.report_url = (
            f"https://www.encar.com/md/sl/mdsl_regcar.do"
            f"?supplyNum={master.get('supplyNum', '')}"
            f"&registrationDate={master.get('registrationDate', '')}"
        )
    
    def _enrich_dates(self, detail: dict, lot: CarLot, record: InspectionRecord) -> None:
        """Enrich date fields."""
        def parse_date8(s: str | None) -> str | None:
            if not s or len(s) != 8 or not s.isdigit():
                return None
            m, d = int(s[4:6]), int(s[6:8])
            if not (1 <= m <= 12 and 1 <= d <= 31):
                return None
            return f"{s[:4]}-{s[4:6]}-{s[6:8]}"
        
        # Validity dates
        if vs := parse_date8(detail.get("validityStartDate")):
            record.valid_from = vs
        if ve := parse_date8(detail.get("validityEndDate")):
            record.valid_until = ve
        
        # First registration
        if fr := parse_date8(detail.get("firstRegistrationDate")):
            record.first_registration = fr
            if not lot.registration_date:
                lot.registration_date = fr
        
        # Inspection mileage
        if detail.get("mileage"):
            record.inspection_mileage = int(detail["mileage"])
    
    def _enrich_technical_details(self, detail: dict, lot: CarLot, record: InspectionRecord) -> None:
        """Enrich technical specifications."""
        # Engine model code
        if detail.get("motorType"):
            lot.raw_data["engine_code"] = detail["motorType"]
        
        # Warranty type
        if detail.get("guarantyType"):
            warranty_type = (detail["guarantyType"] or {}).get("title")
            if warranty_type:
                lot.raw_data["warranty_type"] = warranty_type
    
    def _enrich_recall_status(self, detail: dict, lot: CarLot) -> None:
        """Enrich recall information."""
        recall_flag = detail.get("recall")
        recall_types = [(r.get("title", "")) for r in (detail.get("recallFullFillTypes", []) or [])]
        
        if recall_flag:
            lot.raw_data["recall"] = True
            lot.raw_data["recall_status"] = recall_types or ["miðí"]
    
    def _enrich_car_state(self, detail: dict, lot: CarLot) -> None:
        """Enrich overall car state."""
        if detail.get("carStateType"):
            car_state = (detail["carStateType"] or {}).get("title")
            if car_state:
                lot.raw_data["car_state"] = car_state
    
    def _enrich_mechanical_issues(self, insp: dict, record: InspectionRecord) -> None:
        """Enrich mechanical issues from inners."""
        inners = insp.get("inners", [])
        if not inners:
            return
        
        issues = []
        for inner in inners:
            path = inner.get("path", "")
            title = inner.get("title", "")
            
            for node in inner.get("nodes", []):
                node_path = f"{path}/{title}" if path else title
                status_type = (node.get("statusType", {})).get("title", "")
                
                if status_type in _BAD_INNER:
                    issue_desc = f"{node_path}: {status_type}"
                    issues.append(issue_desc)
        
        if issues:
            record.mechanical_issues = issues
