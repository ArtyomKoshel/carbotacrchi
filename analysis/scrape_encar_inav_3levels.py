"""
iNav scraper — 3 levels only: Manufacturer → ModelGroup → Model (등급)

Captures official EN names from Encar metadata.
Badge/BadgeDetail collected separately via search list API.

Output: analysis/encar_inav_3levels.json
  {
    "manufacturers": [
      {"kr": "현대", "en": "Hyundai", "car_type": "Y", "count": 54367}
    ],
    "model_groups": [
      {"make_kr": "현대", "make_en": "Hyundai", "kr": "싼타페", "en": "Santa Fe", "count": 6810}
    ],
    "models": [
      {"make_kr": "현대", "mg_kr": "싼타페", "kr": "싼타페 (MX5)", "en": null, "count": 492}
    ]
  }

Usage:
  cd parser && python ../analysis/scrape_encar_inav_3levels.py
"""
import sys, json, time, os, signal
from datetime import datetime, timezone
sys.stdout.reconfigure(encoding="utf-8", errors="replace")
import httpx

BASE    = "https://api.encar.com/search/car/list/general"
HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
    "Referer":    "https://www.encar.com/",
    "Accept":     "application/json",
}

_STOP = False
_calls = 0

signal.signal(signal.SIGINT, lambda *_: globals().update(_STOP=True) or print("\n[!] Stopping…"))


def _inav(client, q):
    global _calls
    for attempt in range(4):
        try:
            r = client.get(BASE, params={"count": "true", "q": q, "inav": "|Metadata|Sort"}, timeout=20)
            r.raise_for_status()
            _calls += 1
            return r.json().get("iNav", {})
        except Exception as e:
            time.sleep(2 ** attempt)
    return {}


def _find(container, name, depth=0):
    """Recursive search for named Aspect anywhere in iNav Nodes/Refinements."""
    if depth > 12:
        return []
    for node in container.get("Nodes", []):
        if node.get("Name") == name:
            return node.get("Facets", [])
        for facet in node.get("Facets", []):
            ref = facet.get("Refinements")
            if ref:
                result = _find(ref, name, depth + 1)
                if result:
                    return result
    return []


def _meta(facet, key):
    vals = facet.get("Metadata", {}).get(key, [])
    return vals[0] if vals else None


def main():
    out = os.path.join("..", "analysis", "encar_inav_3levels.json")
    client = httpx.Client(headers=HEADERS, timeout=20)

    manufacturers, model_groups, models = [], [], []

    # ── Level 0: top-level → CarType facets ──────────────────────────────────
    top = _inav(client, "(And.Hidden.N.)")
    car_types = [f for f in _find(top, "CarType") if f.get("Value") in ("Y", "N")]
    print(f"CarTypes: {[f['Value'] for f in car_types]}")

    for ct in car_types:
        if _STOP: break
        ct_val, ct_act = ct["Value"], ct["Action"]

        # ── Level 1: Manufacturers ────────────────────────────────────────────
        makes = _find(_inav(client, ct_act), "Manufacturer")
        print(f"\nCarType={ct_val}: {len(makes)} manufacturers")

        for make in makes:
            if _STOP: break
            make_kr = make["Value"]
            make_en = _meta(make, "EngName") or ""
            manufacturers.append({"kr": make_kr, "en": make_en,
                                   "car_type": ct_val, "count": make["Count"]})
            print(f"  {make_kr} / {make_en}  ({make['Count']:,})")
            time.sleep(0.3)

            # ── Level 2: ModelGroups ──────────────────────────────────────────
            mgs = _find(_inav(client, make["Action"]), "ModelGroup")
            for mg in mgs:
                if _STOP: break
                mg_kr = mg["Value"]
                mg_en = _meta(mg, "EngName") or ""
                model_groups.append({"make_kr": make_kr, "make_en": make_en,
                                     "kr": mg_kr, "en": mg_en, "count": mg["Count"]})
                time.sleep(0.25)

                # ── Level 3: Models (등급) ────────────────────────────────────
                mdls = _find(_inav(client, mg["Action"]), "Model")
                for mdl in mdls:
                    models.append({"make_kr": make_kr, "mg_kr": mg_kr,
                                   "kr": mdl["Value"], "en": _meta(mdl, "EngName"),
                                   "count": mdl["Count"]})
                time.sleep(0.2)

            print(f"    → {len(mgs)} model groups, {sum(1 for m in models if m['make_kr']==make_kr)} models")

    client.close()

    data = {
        "meta": {"scraped_at": datetime.now(timezone.utc).isoformat(), "api_calls": _calls},
        "manufacturers": manufacturers,
        "model_groups":  model_groups,
        "models":        models,
    }
    with open(out, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)

    print(f"\n✓ makes={len(manufacturers)}  model_groups={len(model_groups)}  models={len(models)}")
    print(f"  api_calls={_calls}  (~{_calls*0.27/60:.1f} min)")
    print(f"  Saved → {out}")


if __name__ == "__main__":
    main()
