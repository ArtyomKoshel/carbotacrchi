"""
Test script v2: run the REAL enrichment pipeline on 40 cars,
snapshotting after EACH stage to track which API set each field.
Specifically checks VIN and all critical DB fields.

Usage:  python test_field_sources.py
Output: field_sources_report.txt
"""
from __future__ import annotations

import copy
import json
import sys
import os
import time
from dataclasses import fields as dc_fields

sys.path.insert(0, os.path.dirname(__file__))

from models import CarLot, InspectionRecord
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
)

import httpx
import re as _re

_SAMPLE = 40

# Critical DB fields we must not lose
_CRITICAL_FIELDS = [
    "lot.vin", "lot.plate_number", "lot.registration_date",
    "lot.has_accident", "lot.flood_history", "lot.total_loss_history",
    "lot.lien_status", "lot.seizure_status",
    "lot.owners_count", "lot.insurance_count",
    "lot.body_type", "lot.engine_volume", "lot.drive_type",
    "lot.dealer_name", "lot.dealer_phone",
    "lot.options", "lot.image_url",
    "lot.repair_cost",
]


def _snapshot(lot: CarLot) -> dict:
    snap = {}
    for f in dc_fields(CarLot):
        if f.name == "raw_data":
            continue
        snap[f"lot.{f.name}"] = getattr(lot, f.name)
    for k, v in lot.raw_data.items():
        snap[f"raw.{k}"] = v
    return snap


def _snap_insp(rec: InspectionRecord | None) -> dict:
    if rec is None:
        return {}
    snap = {}
    for f in dc_fields(InspectionRecord):
        if f.name == "details":
            continue
        snap[f"insp.{f.name}"] = getattr(rec, f.name)
    for k, v in (rec.details or {}).items():
        snap[f"insp_d.{k}"] = v
    return snap


def _diff(before: dict, after: dict) -> dict[str, tuple]:
    changes = {}
    all_keys = set(before) | set(after)
    for k in sorted(all_keys):
        old = before.get(k)
        new = after.get(k)
        if old != new:
            changes[k] = (old, new)
    return changes


def _is_set(v) -> bool:
    return v is not None and v != "" and v != 0 and v != [] and v != {}


# ─── STAGES (mirrors real _fetch_lot_enrichment logic) ───────────────────────

STAGES = [
    "search",
    "batch_details",
    "record",
    "inspection",
    # NOT called in new code:
    "inspection_html",
    "diagnosis",
    "sellingpoint",
]


def run_pipeline(client, norm, item):
    """Run enrichment pipeline on one search result.
    Returns: (lot, insp_record, field_origin, stage_results)
      field_origin: {field_key: first_stage_that_set_it}
      stage_results: {stage: {"called": bool, "useful": bool, "bytes": int, "fields_set": [...]}}
    """
    vid = str(item.get("Id", ""))
    field_origin: dict[str, str] = {}
    stage_results: dict[str, dict] = {s: {"called": False, "useful": False, "bytes": 0, "fields_set": []} for s in STAGES}

    # 1. SEARCH
    lot = _lot_from_search(item, norm)
    snap = _snapshot(lot)
    stage_results["search"]["called"] = True
    stage_results["search"]["useful"] = True
    stage_results["search"]["bytes"] = len(json.dumps(item))
    for k, v in snap.items():
        if _is_set(v):
            field_origin[k] = "search"
            stage_results["search"]["fields_set"].append(k)

    # 2. BATCH_DETAILS
    snap_before = _snapshot(lot)
    try:
        details = client.batch_details([vid])
        stage_results["batch_details"]["called"] = True
        stage_results["batch_details"]["bytes"] = len(json.dumps(details))
        if details:
            manage = details[0].get("manage") or {}
            if manage.get("dummy") and manage.get("dummyVehicleId"):
                listing_id = str(manage["dummyVehicleId"])
            else:
                listing_id = str(details[0].get("vehicleId", ""))
            inner_id = str(details[0].get("vehicleId", ""))
            if inner_id and inner_id != vid:
                lot.raw_data["inspect_vehicle_id"] = inner_id
            _enrich_from_detail(lot, details[0], norm)
            stage_results["batch_details"]["useful"] = True
    except Exception as e:
        stage_results["batch_details"]["error"] = str(e)

    snap_after = _snapshot(lot)
    for k, (old, new) in _diff(snap_before, snap_after).items():
        if k not in field_origin and _is_set(new):
            field_origin[k] = "batch_details"
        stage_results["batch_details"]["fields_set"].append(k)

    # 3. RECORD
    _inner_id = lot.raw_data.get("inspect_vehicle_id") or vid
    condition = lot.raw_data.get("condition") or []
    has_record = "Record" in condition
    has_inspection = "Inspection" in condition
    insp_record: InspectionRecord | None = None
    is_certified = False

    if has_record:
        snap_before = {**_snapshot(lot), **_snap_insp(insp_record)}
        try:
            rec = client.record(_inner_id, lot.plate_number or None)
            stage_results["record"]["called"] = True
            stage_results["record"]["bytes"] = len(json.dumps(rec)) if rec else 0
            if rec and rec.get("openData"):
                is_certified = True
                insp_record = _enrich_from_record(lot, rec)
                stage_results["record"]["useful"] = True
        except Exception as e:
            stage_results["record"]["error"] = str(e)

        snap_after = {**_snapshot(lot), **_snap_insp(insp_record)}
        for k, (old, new) in _diff(snap_before, snap_after).items():
            if k not in field_origin and _is_set(new):
                field_origin[k] = "record"
            stage_results["record"]["fields_set"].append(k)

    # 4. INSPECTION (JSON)
    insp_api_ok = False
    if has_inspection:
        snap_before = {**_snapshot(lot), **_snap_insp(insp_record)}
        try:
            insp = client.inspection(_inner_id)
            stage_results["inspection"]["called"] = True
            stage_results["inspection"]["bytes"] = len(json.dumps(insp)) if insp else 0
            if insp:
                if insp_record is None:
                    insp_record = InspectionRecord(lot_id=lot.id, source="encar")
                _enrich_from_inspection(lot, insp, insp_record)
                is_certified = True
                insp_api_ok = True
                stage_results["inspection"]["useful"] = True
        except Exception as e:
            stage_results["inspection"]["error"] = str(e)

        snap_after = {**_snapshot(lot), **_snap_insp(insp_record)}
        for k, (old, new) in _diff(snap_before, snap_after).items():
            if k not in field_origin and _is_set(new):
                field_origin[k] = "inspection"
            stage_results["inspection"]["fields_set"].append(k)

    # 5. INSPECTION_HTML (still tested for comparison — NOT used in prod)
    if has_inspection and not insp_api_ok:
        snap_before = {**_snapshot(lot), **_snap_insp(insp_record)}
        try:
            html = client.inspection_html(_inner_id)
            stage_results["inspection_html"]["called"] = True
            stage_results["inspection_html"]["bytes"] = len(html.encode()) if html else 0
            if html:
                if insp_record is None:
                    insp_record = InspectionRecord(lot_id=lot.id, source="encar")
                _enrich_from_inspection_html(lot, html, insp_record)
                stage_results["inspection_html"]["useful"] = True
        except Exception as e:
            stage_results["inspection_html"]["error"] = str(e)

        snap_after = {**_snapshot(lot), **_snap_insp(insp_record)}
        for k, (old, new) in _diff(snap_before, snap_after).items():
            if k not in field_origin and _is_set(new):
                field_origin[k] = "inspection_html"
            stage_results["inspection_html"]["fields_set"].append(k)

    # 6. DIAGNOSIS
    if is_certified:
        snap_before = {**_snapshot(lot), **_snap_insp(insp_record)}
        try:
            diag = client.diagnosis(vid)
            stage_results["diagnosis"]["called"] = True
            stage_results["diagnosis"]["bytes"] = len(json.dumps(diag)) if diag else 0
            if diag:
                if insp_record is None:
                    insp_record = InspectionRecord(lot_id=lot.id, source="encar")
                _enrich_from_diagnosis(lot, diag, insp_record)
                stage_results["diagnosis"]["useful"] = True
        except Exception as e:
            stage_results["diagnosis"]["error"] = str(e)

        snap_after = {**_snapshot(lot), **_snap_insp(insp_record)}
        for k, (old, new) in _diff(snap_before, snap_after).items():
            if k not in field_origin and _is_set(new):
                field_origin[k] = "diagnosis"
            stage_results["diagnosis"]["fields_set"].append(k)

    # 7. SELLINGPOINT (still tested for comparison — NOT used in prod)
    if not lot.drive_type:
        snap_before = _snapshot(lot)
        try:
            sp = client.sellingpoint(vid)
            stage_results["sellingpoint"]["called"] = True
            stage_results["sellingpoint"]["bytes"] = len(json.dumps(sp)) if sp else 0
            if sp:
                _enrich_from_sellingpoint(lot, sp, norm)
                stage_results["sellingpoint"]["useful"] = True
        except Exception as e:
            stage_results["sellingpoint"]["error"] = str(e)

        snap_after = _snapshot(lot)
        for k, (old, new) in _diff(snap_before, snap_after).items():
            if k not in field_origin and _is_set(new):
                field_origin[k] = "sellingpoint"
            stage_results["sellingpoint"]["fields_set"].append(k)

    return lot, insp_record, field_origin, stage_results


def main():
    norm = EncarNormalizer()
    client = EncarClient()

    print(f"Fetching {_SAMPLE} cars from Encar search API...")
    data = client.search(offset=0, count=_SAMPLE)
    items = data.get("SearchResults", [])
    print(f"Got {len(items)} search results (API total: {data.get('Count', '?')})\n")

    lines: list[str] = []
    # Aggregate stats
    field_first_source: dict[str, dict[str, int]] = {}  # field -> {stage: count}
    critical_coverage: dict[str, dict[str, int]] = {}    # field -> {"filled": N, "source": {stage: N}}
    vin_details: list[dict] = []
    api_totals: dict[str, dict] = {s: {"called": 0, "useful": 0, "bytes": 0} for s in STAGES}
    # Track fields that ONLY come from removed APIs
    fields_only_from_removed: dict[str, dict[str, int]] = {}  # field -> {stage: count}

    for idx, item in enumerate(items):
        vid = str(item.get("Id", ""))
        if not vid:
            continue
        print(f"  [{idx+1}/{len(items)}] {vid}...", end=" ", flush=True)

        lot, insp_record, field_origin, stage_results = run_pipeline(client, norm, item)

        # Per-lot log
        lines.append(f"\n{'='*90}")
        lines.append(f"LOT {idx+1}/{len(items)}: {vid}  |  {lot.make} {lot.model} {lot.year}")
        lines.append(f"{'='*90}")
        lines.append(f"  Condition: {lot.raw_data.get('condition', [])}")

        for stage in STAGES:
            sr = stage_results[stage]
            if not sr["called"]:
                lines.append(f"  [{stage}] — not called")
                continue
            err = sr.get("error")
            if err:
                lines.append(f"  [{stage}] ERROR: {err}")
            elif sr["useful"]:
                flds = sr["fields_set"]
                lines.append(f"  [{stage}] ✓ {sr['bytes']:,}B — set {len(flds)} fields: {', '.join(flds[:10])}{'...' if len(flds)>10 else ''}")
            else:
                lines.append(f"  [{stage}] ✗ {sr['bytes']:,}B — no useful data")

        # Critical fields check
        lines.append(f"\n  ── CRITICAL FIELDS ──")
        for cf in _CRITICAL_FIELDS:
            parts = cf.split(".", 1)
            val = getattr(lot, parts[1], None) if parts[0] == "lot" else lot.raw_data.get(parts[1])
            origin = field_origin.get(cf, "—")
            filled = _is_set(val)
            marker = "✓" if filled else "✗ MISSING"
            val_short = repr(val)[:60] if filled else ""
            lines.append(f"    {marker:12} {cf:<30} src={origin:<18} {val_short}")

            critical_coverage.setdefault(cf, {"filled": 0, "sources": {}})
            if filled:
                critical_coverage[cf]["filled"] += 1
                critical_coverage[cf]["sources"][origin] = critical_coverage[cf]["sources"].get(origin, 0) + 1

        # VIN detail
        vin_details.append({
            "id": vid,
            "vin": lot.vin,
            "vin_source": field_origin.get("lot.vin", "—"),
            "plate": lot.plate_number,
        })

        # Aggregate
        for field, stage in field_origin.items():
            field_first_source.setdefault(field, {})
            field_first_source[field][stage] = field_first_source[field].get(stage, 0) + 1

        for stage in STAGES:
            sr = stage_results[stage]
            if sr["called"]:
                api_totals[stage]["called"] += 1
            if sr["useful"]:
                api_totals[stage]["useful"] += 1
            api_totals[stage]["bytes"] += sr["bytes"]

        # Track fields that would be lost
        for field, stage in field_origin.items():
            if stage in ("inspection_html", "sellingpoint"):
                fields_only_from_removed.setdefault(field, {})
                fields_only_from_removed[field][stage] = fields_only_from_removed[field].get(stage, 0) + 1

        print(f"VIN={lot.vin or 'NONE'} src={field_origin.get('lot.vin','—')}")
        time.sleep(0.2)

    # ══════════════════════════════════════════════════════════════════════════
    #  SUMMARY
    # ══════════════════════════════════════════════════════════════════════════
    N = len(items)
    lines.append(f"\n\n{'#'*90}")
    lines.append(f"SUMMARY — {N} lots")
    lines.append(f"{'#'*90}")

    lines.append(f"\n{'='*90}")
    lines.append(f"VIN COVERAGE (the field you asked about)")
    lines.append(f"{'='*90}")
    vin_filled = sum(1 for v in vin_details if v["vin"])
    vin_missing = [v for v in vin_details if not v["vin"]]
    lines.append(f"  VIN filled: {vin_filled}/{N} ({vin_filled/N*100:.0f}%)")
    vin_src_counts: dict[str, int] = {}
    for v in vin_details:
        if v["vin"]:
            vin_src_counts[v["vin_source"]] = vin_src_counts.get(v["vin_source"], 0) + 1
    for src, cnt in sorted(vin_src_counts.items(), key=lambda x: -x[1]):
        lines.append(f"    from {src}: {cnt}/{vin_filled}")
    if vin_missing:
        lines.append(f"  VIN MISSING for: {[v['id'] for v in vin_missing]}")

    lines.append(f"\n{'='*90}")
    lines.append(f"CRITICAL FIELDS COVERAGE")
    lines.append(f"{'='*90}")
    for cf in _CRITICAL_FIELDS:
        info = critical_coverage.get(cf, {"filled": 0, "sources": {}})
        pct = info["filled"] / N * 100
        src_parts = [f"{s}={c}" for s, c in sorted(info["sources"].items(), key=lambda x: -x[1])]
        marker = "✓" if pct >= 90 else ("⚠" if pct >= 50 else "✗")
        lines.append(f"  {marker} {cf:<35} {info['filled']:>3}/{N} ({pct:5.1f}%)  src: {', '.join(src_parts) or '—'}")

    lines.append(f"\n{'='*90}")
    lines.append(f"API STATS")
    lines.append(f"{'='*90}")
    lines.append(f"  {'Stage':<20} {'Called':>7} {'Useful':>7} {'Total KB':>10}")
    lines.append(f"  {'-'*50}")
    for s in STAGES:
        t = api_totals[s]
        lines.append(f"  {s:<20} {t['called']:>7} {t['useful']:>7} {t['bytes']/1024:>9.1f}K")

    lines.append(f"\n{'='*90}")
    lines.append(f"FIELDS THAT WOULD BE LOST (only from inspection_html / sellingpoint)")
    lines.append(f"{'='*90}")
    if fields_only_from_removed:
        for field, sources in sorted(fields_only_from_removed.items()):
            for src, cnt in sources.items():
                lines.append(f"  ⚠ {field:<40} ONLY from {src} ({cnt}/{N} lots)")
    else:
        lines.append(f"  ✓ NONE — all fields are covered by other APIs")

    lines.append(f"\n{'='*90}")
    lines.append(f"FULL FIELD → FIRST-SOURCE MAP")
    lines.append(f"{'='*90}")
    for field in sorted(field_first_source.keys()):
        sources = field_first_source[field]
        parts = [f"{s}={c}" for s, c in sorted(sources.items(), key=lambda x: -x[1])]
        lines.append(f"  {field:<45} {', '.join(parts)}")

    report = "\n".join(lines)
    out_path = os.path.join(os.path.dirname(__file__), "field_sources_report.txt")
    with open(out_path, "w", encoding="utf-8") as f:
        f.write(report)
    print(f"\n{'='*60}")
    # Print just the key summaries to console
    for line in lines:
        if any(x in line for x in ["VIN", "CRITICAL", "WOULD BE LOST", "✗", "⚠", "NONE —", "SUMMARY", "===", "---", "Stage", "search", "batch", "record", "inspection", "diagnosis", "selling", "from "]):
            print(line)
    print(f"\n✅ Full report: {out_path}")

    client.close()


if __name__ == "__main__":
    main()
