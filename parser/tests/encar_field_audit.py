"""
Encar Field Audit — runs full enrichment on N cars and reports field coverage.
Usage: python tests/encar_field_audit.py [--cars 10] [--out audit.log]
"""
from __future__ import annotations

import argparse
import json
import sys
import os
import time
from datetime import datetime
from dataclasses import fields as dc_fields

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from parsers.encar.client import EncarClient
from parsers.encar.normalizer import EncarNormalizer
from parsers.encar import (
    _lot_from_search,
    _enrich_from_detail,
    _enrich_from_record,
    _enrich_from_inspection,
    _enrich_from_inspection_html,
    _enrich_from_diagnosis,
    _enrich_from_sellingpoint,
    _enrich_from_verification,
)
from models import CarLot, InspectionRecord


# ── All CarLot fields grouped by category ────────────────────────────────────
FIELD_GROUPS = {
    "Identity":       ["id", "source", "lot_url", "image_url"],
    "Vehicle":        ["make", "model", "year", "trim", "vin", "plate_number"],
    "Spec":           ["body_type", "fuel", "transmission", "drive_type",
                       "cylinders", "engine_volume", "fuel_economy"],
    "Condition":      ["mileage", "mileage_grade", "color", "seat_color", "has_keys",
                       "damage", "secondary_damage"],
    "History":        ["has_accident", "insurance_count", "owners_count",
                       "flood_history", "total_loss_history",
                       "registration_date"],
    "Financial":      ["price", "price_krw", "lien_status", "seizure_status",
                       "tax_paid", "repair_cost", "retail_value",
                       "new_car_price_ratio", "ai_price_min", "ai_price_max"],
    "Dealer":         ["dealer_name", "dealer_company", "dealer_location",
                       "dealer_phone", "dealer_description"],
    "Extras":         ["options", "paid_options", "warranty_text",
                       "title", "document", "location"],
}

# InspectionRecord fields
INSP_FIELDS = [
    "cert_no", "inspection_date", "valid_from", "valid_until", "report_url",
    "first_registration", "inspection_mileage", "insurance_fee",
    "has_accident", "has_outer_damage", "has_flood", "has_fire", "has_tuning",
    "accident_detail", "outer_detail",
]

# raw_data keys we explicitly track
RAW_DATA_KEYS = [
    "engine_code", "warranty_type", "recall", "recall_status", "car_state",
    "mechanical_issues", "diagnosis_center",
    "accident_cnt", "owner_cnt", "repair_cost_total",
    "flood", "seizure", "lien",
    "drive_type", "photos",
    "photo_count", "front_tinting", "keys_count", "tire_depth_mm",
]


def run_audit(n_cars: int = 5) -> list[dict]:
    client = EncarClient()
    norm = EncarNormalizer()
    results = []

    data = client.search(count=n_cars)
    items = data.get("SearchResults", [])[:n_cars]
    print(f"Fetched {len(items)} search results\n")

    for i, item in enumerate(items):
        vid = str(item.get("Id", ""))
        print(f"[{i+1}/{len(items)}] Vehicle {vid} ─ {item.get('Manufacturer')} {item.get('Model')}")

        entry: dict = {"vehicle_id": vid, "steps": {}, "lot": None, "inspection": None}

        # Step 1: search → lot
        lot = _lot_from_search(item, norm)
        entry["steps"]["search"] = "ok"

        # Step 2: detail
        try:
            detail = client.detail(vid)
            _enrich_from_detail(lot, detail, norm)
            entry["steps"]["detail"] = "ok"
        except Exception as e:
            entry["steps"]["detail"] = f"ERROR: {e}"

        time.sleep(0.5)

        insp_record: InspectionRecord | None = None

        # Step 3: record (use inner vehicle ID)
        _insp_id = lot.raw_data.get("inspect_vehicle_id") or vid
        if lot.plate_number:
            try:
                rec = client.record(_insp_id, lot.plate_number)
                if rec and rec.get("openData"):
                    insp_record = _enrich_from_record(lot, rec)
                    entry["steps"]["record"] = "ok"
                else:
                    entry["steps"]["record"] = "no openData"
            except Exception as e:
                entry["steps"]["record"] = f"ERROR: {e}"
            time.sleep(0.3)
        else:
            entry["steps"]["record"] = "SKIP — no plate_number"

        # Step 4: inspection (use inner vehicle ID from photo paths if available)
        _insp_id = lot.raw_data.get("inspect_vehicle_id") or vid
        insp_api_ok = False
        try:
            insp = client.inspection(_insp_id)
            if insp:
                if insp_record is None:
                    insp_record = InspectionRecord(lot_id=vid, source="encar")
                _enrich_from_inspection(lot, insp, insp_record)
                entry["steps"]["inspection"] = "ok"
                insp_api_ok = True
            else:
                entry["steps"]["inspection"] = "empty response"
        except Exception as e:
            entry["steps"]["inspection"] = f"ERROR: {e}"
        time.sleep(0.3)

        # Step 4b: HTML inspection fallback
        if not insp_api_ok:
            html = client.inspection_html(_insp_id)
            if html:
                if insp_record is None:
                    insp_record = InspectionRecord(lot_id=vid, source="encar")
                _enrich_from_inspection_html(lot, html, insp_record)
                entry["steps"]["inspection_html"] = "ok (fallback)"
            else:
                entry["steps"]["inspection_html"] = "empty"
            time.sleep(0.3)

        # Step 5: diagnosis
        try:
            diag = client.diagnosis(vid)
            if diag:
                if insp_record is None:
                    insp_record = InspectionRecord(lot_id=vid, source="encar")
                _enrich_from_diagnosis(lot, diag, insp_record)
                entry["steps"]["diagnosis"] = "ok"
            else:
                entry["steps"]["diagnosis"] = "empty"
        except Exception as e:
            entry["steps"]["diagnosis"] = f"ERROR: {e}"
        time.sleep(0.3)

        # Step 6: sellingpoint
        try:
            sp = client.sellingpoint(vid)
            if sp:
                _enrich_from_sellingpoint(lot, sp, norm)
                entry["steps"]["sellingpoint"] = "ok"
            else:
                entry["steps"]["sellingpoint"] = "empty"
        except Exception as e:
            entry["steps"]["sellingpoint"] = f"ERROR: {e}"
        time.sleep(0.3)

        entry["lot"] = {f: getattr(lot, f, None) for group in FIELD_GROUPS.values() for f in group}
        entry["lot"]["raw_keys"] = sorted(lot.raw_data.keys()) if lot.raw_data else []
        entry["lot"]["raw_tracked"] = {k: lot.raw_data.get(k) for k in RAW_DATA_KEYS}
        if insp_record:
            entry["inspection"] = {f: getattr(insp_record, f, None) for f in INSP_FIELDS}
        else:
            entry["inspection"] = None

        results.append(entry)
        print(f"   steps: {entry['steps']}")
        print(f"   vin={lot.vin}  plate={lot.plate_number}  drive={lot.drive_type}  "
              f"accident={lot.has_accident}  lien={lot.lien_status}  owners={lot.owners_count}")

    client.close()
    return results


def build_report(results: list[dict]) -> str:
    lines = []
    ts = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    lines += [
        "=" * 72,
        f"  ENCAR FIELD AUDIT  —  {ts}",
        f"  Cars sampled: {len(results)}",
        "=" * 72, "",
    ]

    # ── Per-car table ─────────────────────────────────────────────────────────
    lines.append("── PER-CAR SUMMARY ────────────────────────────────────────────────────\n")
    for r in results:
        vid  = r["vehicle_id"]
        lot  = r["lot"] or {}
        insp = r["inspection"] or {}
        raw  = lot.get("raw_tracked") or {}
        lines.append(f"Vehicle {vid}  make={lot.get('make')}  model={lot.get('model')}  year={lot.get('year')}")
        lines.append(f"  Steps: {r['steps']}")

        for group, fnames in FIELD_GROUPS.items():
            row = "  " + group + ":"
            parts = []
            for f in fnames:
                v = lot.get(f)
                if v is None or v == "" or v == []:
                    parts.append(f"\033[33m{f}=NULL\033[0m")
                else:
                    disp = str(v)[:30]
                    parts.append(f"{f}={disp}")
            lines.append(row)
            lines.append("    " + "  ".join(parts))

        if insp:
            lines.append("  Inspection:")
            iparts = []
            for f in INSP_FIELDS:
                v = insp.get(f)
                if v is None:
                    iparts.append(f"{f}=NULL")
                else:
                    iparts.append(f"{f}={str(v)[:30]}")
            lines.append("    " + "  ".join(iparts))

        raw_keys = lot.get("raw_keys") or []
        lines.append(f"  raw_data keys: {raw_keys}")
        # Tracked raw_data fields
        rt = lot.get("raw_tracked") or {}
        filled_rt = {k: v for k, v in rt.items() if v not in (None, "", [], False)}
        missing_rt = [k for k, v in rt.items() if v in (None, "", [], False)]
        if filled_rt:
            lines.append(f"  raw_data filled: {filled_rt}")
        if missing_rt:
            lines.append(f"  raw_data NULL:   {missing_rt}")
        lines.append("")

    # ── Field coverage matrix ──────────────────────────────────────────────────
    lines.append("\n── FIELD COVERAGE MATRIX ──────────────────────────────────────────────\n")
    lines.append(f"{'Field':<28} {'Filled':>6} {'%':>5}  Status")
    lines.append("-" * 55)

    all_fields = [f for group in FIELD_GROUPS.values() for f in group]
    n = len(results)
    missing_fields = []

    for f in all_fields:
        filled = sum(
            1 for r in results
            if (r["lot"] or {}).get(f) not in (None, "", [], 0)
        )
        pct = filled / n * 100
        status = "✓" if pct == 100 else ("~" if pct > 0 else "✗ MISSING")
        if pct == 0:
            missing_fields.append(f)
        lines.append(f"  {f:<26} {filled:>6}/{n:<3} {pct:>4.0f}%  {status}")

    # ── Inspection coverage ────────────────────────────────────────────────────
    lines.append("")
    lines.append(f"{'Inspection field':<28} {'Filled':>6} {'%':>5}  Status")
    lines.append("-" * 55)
    insp_results = [r for r in results if r["inspection"]]
    ni = len(insp_results) or 1

    for f in INSP_FIELDS:
        filled = sum(
            1 for r in insp_results
            if (r["inspection"] or {}).get(f) not in (None, "", [])
        )
        pct = filled / ni * 100
        status = "✓" if pct == 100 else ("~" if pct > 0 else "✗ MISSING")
        lines.append(f"  {f:<26} {filled:>6}/{ni:<3} {pct:>4.0f}%  {status}")

    # ── Missing / gap analysis ────────────────────────────────────────────────
    lines += ["", "── GAP ANALYSIS ───────────────────────────────────────────────────────", ""]

    no_plate = sum(1 for r in results if not (r["lot"] or {}).get("plate_number"))
    no_insp  = sum(1 for r in results if r["inspection"] is None)
    no_drive = sum(1 for r in results if not (r["lot"] or {}).get("drive_type"))

    gaps = [
        ("plate_number missing",   no_plate,  "Needed for record API — comes from detail API"),
        ("inspection unavailable", no_insp,   "Lot may have no inspection cert on Encar"),
        ("drive_type missing",     no_drive,  "Comes from sellingpoint API — not always present"),
    ]
    for label, cnt, reason in gaps:
        if cnt:
            lines.append(f"  ⚠  {label}: {cnt}/{n} cars")
            lines.append(f"     Reason: {reason}")

    if missing_fields:
        lines.append("")
        lines.append("  Fields NEVER populated across all sampled cars:")
        for f in missing_fields:
            explanations = {
                "cylinders":         "Not in any Encar API response — unavailable",
                "fuel_economy":      "Not exposed in Encar APIs — unavailable",
                "damage":            "Encar uses inspection.outers, not a damage text field",
                "secondary_damage":  "Same as above",
                "mileage_grade":     "Not in Encar API",
                "tax_paid":          "Not applicable in Korean market",
                "document":          "Not applicable — no title document concept",
                "title":             "All Korean cars have clean title by default",
                "dealer_name":       "Comes from detail.partnership — may be null for private sellers",
                "ai_price_min":      "Calculated externally, not from parser",
                "ai_price_max":      "Calculated externally, not from parser",
                "new_car_price_ratio": "Requires new car price DB — not in Encar API",
                "retail_value":      "Estimated retail — not in Encar API",
                "insurance_fee":     "Not in Encar APIs",
                "warranty_text":     "Not always present",
            }
            reason = explanations.get(f, "Not found in any Encar API endpoint")
            lines.append(f"    • {f:<24} — {reason}")

    lines += ["", "=" * 72]
    return "\n".join(lines)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--cars", type=int, default=5)
    parser.add_argument("--out",  type=str, default="audit_encar.log")
    args = parser.parse_args()

    results = run_audit(n_cars=args.cars)
    report  = build_report(results)

    # Write plain (no ANSI) to file
    import re
    plain = re.sub(r"\033\[[0-9;]+m", "", report)
    out_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), args.out)
    with open(out_path, "w", encoding="utf-8") as f:
        f.write(plain)

    # Also save raw JSON
    json_path = out_path.replace(".log", ".json")
    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(results, f, ensure_ascii=False, indent=2, default=str)

    print("\n" + report)
    print(f"\n✓ Report written to: {out_path}")
    print(f"✓ Raw JSON written to: {json_path}")
