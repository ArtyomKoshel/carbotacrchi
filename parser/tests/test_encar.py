"""
Quick smoke test for Encar parser — runs locally without Docker.
Usage: python -m pytest parser/tests/test_encar.py -v -s
   or: python parser/tests/test_encar.py
"""
from __future__ import annotations

import json
import sys
import os

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from parsers.encar.client import EncarClient
from parsers.encar.normalizer import EncarNormalizer
from parsers.encar import (
    _lot_from_search, _enrich_from_detail,
    _enrich_from_record, _enrich_from_inspection,
    _enrich_from_sellingpoint, _enrich_from_verification,
    _enrich_from_diagnosis,
)
from models import InspectionRecord


def test_search_returns_results():
    client = EncarClient()
    data = client.search(count=3)
    assert "SearchResults" in data, f"Unexpected response: {list(data.keys())}"
    results = data["SearchResults"]
    assert len(results) > 0, "Empty search results"
    print(f"\n[search] Count={data.get('Count')}, got {len(results)} items")
    item = results[0]
    print(f"  First: Id={item.get('Id')} Manufacturer={item.get('Manufacturer')} "
          f"Model={item.get('Model')} Price={item.get('Price')} Mileage={item.get('Mileage')}")
    client.close()


def test_lot_from_search():
    client = EncarClient()
    norm = EncarNormalizer()
    data = client.search(count=3)
    for item in data["SearchResults"][:2]:
        lot = _lot_from_search(item, norm)
        print(f"\n[lot] id={lot.id} make={lot.make} model={lot.model} "
              f"year={lot.year} price={lot.price} mileage={lot.mileage} "
              f"fuel={lot.fuel} url={lot.lot_url}")
        assert lot.id
        assert lot.make
        assert lot.year > 2000
    client.close()


def test_detail_fetch():
    client = EncarClient()
    norm = EncarNormalizer()
    data = client.search(count=2)
    item = data["SearchResults"][0]
    vid = item["Id"]

    lot = _lot_from_search(item, norm)
    print(f"\n[before detail] vin={lot.vin} color={lot.color} tx={lot.transmission}")

    detail = client.detail(vid)
    _enrich_from_detail(lot, detail, norm)

    print(f"[after  detail] vin={lot.vin} color={lot.color} tx={lot.transmission} "
          f"fuel={lot.fuel} body={lot.body_type} engine={lot.engine_volume}L "
          f"plate={lot.plate_number} dealer={lot.dealer_company}")
    client.close()


def test_batch_details():
    client = EncarClient()
    norm = EncarNormalizer()
    data = client.search(count=3)
    items = data["SearchResults"][:3]
    ids = [i["Id"] for i in items]
    lots = {str(i["Id"]): _lot_from_search(i, norm) for i in items}

    details = client.batch_details(ids)
    print(f"\n[batch] fetched {len(details)} details")
    for d in details:
        vid = str(d.get("vehicleId", ""))
        if vid in lots:
            _enrich_from_detail(lots[vid], d, norm)
            print(f"  {vid}: vin={lots[vid].vin} color={lots[vid].color} "
                  f"tx={lots[vid].transmission} fuel={lots[vid].fuel}")
    client.close()


def test_record_and_inspection():
    """Test record + inspection for known vehicle 40738066 (plate 41루4641)."""
    client = EncarClient()
    norm = EncarNormalizer()
    vid = "40738066"
    plate = "41루4641"

    detail = client.detail(vid)
    from parsers.encar import _lot_from_search
    # Build minimal lot manually
    lot = _lot_from_search({
        "Id": vid, "Manufacturer": "GM대우", "Model": "올란도",
        "Badge": "", "FuelType": "LPG", "Year": 201705.0, "FormYear": "2017",
        "Price": 420.0, "Mileage": 188705.0, "Photos": [],
    }, norm)
    lot.plate_number = plate

    # Record API
    rec = client.record(vid, plate)
    assert rec and rec.get("openData"), f"Record not available: {rec}"
    insp_record = _enrich_from_record(lot, rec)
    print(f"\n[record] accidents={lot.has_accident} cnt={lot.insurance_count} "
          f"owners={lot.owners_count} flood={lot.flood_history} "
          f"repair_cost=₩{lot.repair_cost:,}" if lot.repair_cost else
          f"\n[record] accidents={lot.has_accident} cnt={lot.insurance_count} owners={lot.owners_count}")
    print(f"  accident_detail:\n{insp_record.accident_detail}")
    assert lot.has_accident is True
    assert lot.insurance_count == 5
    assert lot.lien_status    == "clean"    # loan=0
    assert lot.seizure_status == "clean"    # robberCnt=0
    assert insp_record.details.get("plate_change_cnt") == 0
    # type=2 → my-fault (3 items), type=3 → other-fault (2 items)
    lines = (insp_record.accident_detail or "").splitlines()
    my_fault    = [l for l in lines if "my-fault"    in l]
    other_fault = [l for l in lines if "other-fault" in l]
    assert len(my_fault)    == 3, f"Expected 3 my-fault, got: {my_fault}"
    assert len(other_fault) == 2, f"Expected 2 other-fault, got: {other_fault}"

    # Validate second example data inline: loan=1→lien, type=3→other-fault, plate changed
    mock_rec2 = {
        "openData": True, "firstDate": "2022-01-13",
        "myAccidentCnt": 0, "otherAccidentCnt": 1, "accidentCnt": 1,
        "ownerChangeCnt": 1, "robberCnt": 0, "loan": 1,
        "totalLossCnt": 0, "floodTotalLossCnt": 0, "floodPartLossCnt": None,
        "myAccidentCost": 0, "otherAccidentCost": 2250219,
        "carNoChangeCnt": 1, "carInfoChanges": [{"date": "2022-01-13", "carNo": "149하XXXX"}, {"date": "2026-02-10", "carNo": "372마XXXX"}],
        "ownerChanges": ["2026-02-10"],
        "accidents": [{"type": "3", "date": "2025-07-28", "insuranceBenefit": 2364048, "partCost": 131000, "laborCost": 1274120, "paintingCost": 845099}],
    }
    from models import CarLot as _CL
    lot2 = _CL(id="41530692", source="encar", make="Hyundai", model="Test", year=2022, price=3000, mileage=50000, lot_url="")
    rec2 = _enrich_from_record(lot2, mock_rec2)
    assert lot2.lien_status    == "lien",    f"Expected lien, got {lot2.lien_status}"
    assert lot2.seizure_status == "clean"
    assert lot2.has_accident   is True
    assert lot2.insurance_count == 1
    assert lot2.owners_count    == 1
    lines2 = (rec2.accident_detail or "").splitlines()
    assert len(lines2) == 1 and "other-fault" in lines2[0], f"Bad: {lines2}"
    assert rec2.details.get("plate_change_cnt") == 1
    assert len(rec2.details.get("plate_changes", [])) == 2
    print(f"  [mock2] lien={lot2.lien_status} type3→{lines2[0]}")

    # Inspection API
    insp = client.inspection(vid)
    assert insp, "No inspection data"
    _enrich_from_inspection(lot, insp, insp_record)
    print(f"[inspection] vin={lot.vin} flood={insp_record.has_flood} "
          f"tuning={insp_record.has_tuning} outer_damage={insp_record.has_outer_damage}")
    print(f"  outer_detail:\n{insp_record.outer_detail}")
    print(f"  valid: {insp_record.valid_from} → {insp_record.valid_until}")
    assert lot.vin == "KLAYA75ADHK626090"
    assert insp_record.has_outer_damage is True
    assert insp_record.report_url and "inspectionViewNew" in insp_record.report_url
    print(f"  report_url: {insp_record.report_url}")

    # Sellingpoint (drive_type)
    sp = client.sellingpoint(vid)
    assert sp, "No sellingpoint data"
    _enrich_from_sellingpoint(lot, sp, norm)
    print(f"[sellingpoint] drive_type={lot.drive_type} selling_point={lot.raw_data.get('selling_point', '')[:60]}")
    assert lot.drive_type is not None, "drive_type not parsed from sellingpoint"
    client.close()


def test_diagnosis():
    """Test Encar internal diagnosis for certified vehicle 41517291."""
    client = EncarClient()
    norm   = EncarNormalizer()
    vid    = "41517291"

    lot = _lot_from_search({
        "Id": vid, "Manufacturer": "현대", "Model": "테스트",
        "Badge": "", "FuelType": "가솔린", "Year": 202201.0, "FormYear": "2022",
        "Price": 3000.0, "Mileage": 50000.0, "Photos": [],
    }, norm)

    # Record without plate (certified car)
    rec = client.record(vid)
    assert rec and rec.get("openData"), f"Expected openData=True, got {rec}"
    insp_record = _enrich_from_record(lot, rec)
    print(f"\n[record_no_plate] openData=True accidentCnt={lot.insurance_count} owners={lot.owners_count}")

    # Inspection (performance check — also parses motorType, recall, inners)
    insp = client.inspection(vid)
    assert insp, "No inspection data"
    _enrich_from_inspection(lot, insp, insp_record)
    print(f"[inspection] engine_code={lot.raw_data.get('engine_code')}  warranty={lot.raw_data.get('warranty_type')}")
    print(f"  recall={lot.raw_data.get('recall')}  recall_status={lot.raw_data.get('recall_status')}")
    print(f"  mechanical_issues={lot.raw_data.get('mechanical_issues')}")
    print(f"  car_state={lot.raw_data.get('car_state')}")
    assert lot.raw_data.get("engine_code") == "D4CB"
    assert lot.raw_data.get("recall") is True
    assert lot.raw_data.get("recall_status") == ["미이행"]
    mech = lot.raw_data.get("mechanical_issues") or []
    assert any("누유" in m for m in mech), f"Expected oil leak in mechanical_issues: {mech}"
    # outers: 8 damaged parts (X=교환, W=판금/용접)
    outer = insp_record.outer_detail or ""
    print(f"  outer_detail from inspection outers:\n{outer}")
    assert outer.count("\n") >= 7, f"Expected ≥8 outer lines, got: {outer}"
    assert "후드" in outer
    assert "사이드실" in outer

    # Diagnosis (body panel check)
    diag = client.diagnosis(vid)
    assert diag, "No diagnosis data"
    _enrich_from_diagnosis(lot, diag, insp_record)
    print(f"[diagnosis] center={lot.raw_data.get('diagnosis_center')}")
    print(f"  outer_damage={insp_record.has_outer_damage}")
    print(f"  outer_detail=\n{insp_record.outer_detail}")
    items = (insp_record.details or {}).get("diagnosis", {}).get("items", [])
    print(f"  checker_comment={((insp_record.details or {}).get('diagnosis') or {}).get('checker_comment', '')[:80]}")

    assert insp_record.has_outer_damage is True  # HOOD+FENDERS replaced
    assert len(items) >= 3
    client.close()


def test_verification():
    """Test verification parser logic using mocked data (endpoint requires auth in prod)."""
    norm = EncarNormalizer()
    vid = "41530692"

    lot = _lot_from_search({
        "Id": vid, "Manufacturer": "현대", "Model": "테스트",
        "Badge": "", "FuelType": "가솔린", "Year": 202201.0, "FormYear": "2022",
        "Price": 3000.0, "Mileage": 50000.0, "Photos": [],
    }, norm)

    # Use the sample payload shown in docs (endpoint returns 401 without auth)
    mock_vdata = {
        "carId": int(vid),
        "items": [
            {"option": {"id": 10}, "value": "2"},    # 2 keys
            {"option": {"id": 16}, "value": "INCLUDE"},  # tinting
            {"option": {"id": 327}, "value": "6"},   # tire FR
            {"option": {"id": 328}, "value": "6"},   # tire RR
            {"option": {"id": 329}, "value": "6"},   # tire RL
            {"option": {"id": 330}, "value": "6"},   # tire FL
        ],
        "itemPictures": [
            {"optionId": 1, "attachments": [{"id": 1, "key": "/home/files/test.jpg", "name": "test.jpg"}], "attachmentsLength": 1},
        ],
    }
    _enrich_from_verification(lot, mock_vdata)

    print(f"\n[verification] has_keys={lot.has_keys} keys_count={lot.raw_data.get('keys_count')}")
    print(f"  tire_depth_mm={lot.raw_data.get('tire_depth_mm')}")
    print(f"  front_tinting={lot.raw_data.get('front_tinting')}")
    photos = lot.raw_data.get('verify_photos', [])
    print(f"  verify_photos={len(photos)}: {photos[0] if photos else 'none'}")

    assert lot.has_keys is True
    assert lot.raw_data.get("keys_count") == 2
    assert lot.raw_data.get("tire_depth_mm") == {"fl": 6, "fr": 6, "rl": 6, "rr": 6}
    assert lot.raw_data.get("front_tinting") is True
    assert len(photos) == 1


if __name__ == "__main__":
    print("=== Encar Parser Smoke Test ===\n")
    tests = [
        ("search", test_search_returns_results),
        ("lot_from_search", test_lot_from_search),
        ("detail", test_detail_fetch),
        ("batch_details", test_batch_details),
        ("record_and_inspection", test_record_and_inspection),
        ("diagnosis", test_diagnosis),
        ("verification", test_verification),
    ]
    failed = []
    for name, fn in tests:
        try:
            fn()
            print(f"\n✓ {name}")
        except Exception as e:
            print(f"\n✗ {name}: {e}")
            import traceback; traceback.print_exc()
            failed.append(name)

    print(f"\n{'All passed' if not failed else 'FAILED: ' + ', '.join(failed)}")
    sys.exit(1 if failed else 0)
