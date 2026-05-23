#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import os
import re
from collections import Counter
from datetime import datetime

TRIM_HINTS = {
    "프레스티지", "노블레스", "시그니처", "시그니쳐", "익스클루시브", "인스퍼레이션", "모던", "럭셔리", "스마트", "프리미엄",
    "캘리그래피", "르블랑", "아방가르드", "고급형", "기본형", "리미티드", "컴포트", "스타일", "트렌디", "레드라인", "AMG", "AMG Line",
    "M 스포츠", "M Sport", "N Line", "GT Line", "Business", "비즈니스", "모빌리티",
}

ENGINE_RE = re.compile(r"[0-9]+(?:\.[0-9]+)?(?:T|D|L)?", re.IGNORECASE)
DRIVE_RE = re.compile(r"(^|\s)(2wd|4wd|awd|fwd|rwd|xdrive|sdrive|4matic\+?)(\s|$)", re.IGNORECASE)
FUEL_RE = re.compile(r"(디젤|가솔린|하이브리드|HEV|LPG|전기|EV|TDI|TFSI|GDI|e-VGT)", re.IGNORECASE)
SEAT_RE = re.compile(r"\b([0-9]{1,2})인승\b")
GEN_RE = re.compile(r"\(([A-Za-z0-9]{2,6})\)|\b([A-Z]{1,2}[0-9]{1,3})\b")


def parse_create_table_columns(path: str, table_name: str = "lots") -> list[str]:
    cols: list[str] = []
    in_block = False
    start_pat = f"CREATE TABLE `{table_name}`"
    with open(path, "r", encoding="utf-8", errors="replace") as f:
        for line in f:
            if not in_block and start_pat in line:
                in_block = True
                continue
            if in_block:
                s = line.strip()
                if s.startswith("`"):
                    cols.append(s.split("`", 2)[1])
                elif s.startswith(") ENGINE="):
                    break
    return cols


def iter_insert_statements(path: str, table_name: str = "lots"):
    start = f"INSERT INTO `{table_name}` VALUES"
    collecting = False
    buf: list[str] = []

    with open(path, "r", encoding="utf-8", errors="replace") as f:
        for line in f:
            if not collecting:
                if line.startswith(start):
                    collecting = True
                    buf = [line]
                    if line.rstrip().endswith(";"):
                        yield "".join(buf)
                        collecting = False
                        buf = []
            else:
                buf.append(line)
                if line.rstrip().endswith(";"):
                    yield "".join(buf)
                    collecting = False
                    buf = []


def iter_tuples(values_sql: str):
    in_str = False
    esc = False
    depth = 0
    start_idx = None

    for i, ch in enumerate(values_sql):
        if in_str:
            if esc:
                esc = False
            elif ch == "\\":
                esc = True
            elif ch == "'":
                in_str = False
            continue

        if ch == "'":
            in_str = True
            continue

        if ch == "(":
            if depth == 0:
                start_idx = i + 1
            depth += 1
        elif ch == ")":
            depth -= 1
            if depth == 0 and start_idx is not None:
                yield values_sql[start_idx:i]
                start_idx = None


def split_fields(tuple_body: str) -> list[str]:
    fields: list[str] = []
    cur: list[str] = []
    in_str = False
    esc = False

    for ch in tuple_body:
        if in_str:
            cur.append(ch)
            if esc:
                esc = False
            elif ch == "\\":
                esc = True
            elif ch == "'":
                in_str = False
            continue

        if ch == "'":
            in_str = True
            cur.append(ch)
        elif ch == ",":
            fields.append("".join(cur).strip())
            cur = []
        else:
            cur.append(ch)

    fields.append("".join(cur).strip())
    return fields


def decode_sql_value(v: str):
    if v == "NULL":
        return None
    if len(v) >= 2 and v[0] == "'" and v[-1] == "'":
        s = v[1:-1]
        s = s.replace("\\'", "'")
        s = s.replace("\\\\", "\\")
        return s
    return v


def safe_get(row: dict[str, object], key: str) -> str:
    v = row.get(key)
    return "" if v is None else str(v)


def analyze_dump(dump_path: str, out_dir: str) -> dict:
    cols = parse_create_table_columns(dump_path, "lots")
    if not cols:
        raise RuntimeError("Could not parse lots columns from dump.")

    col_count = len(cols)

    metrics = Counter()
    make_empty_trim = Counter()
    model_empty_trim = Counter()
    trim_values = Counter()
    generation_values = Counter()
    seat_token_values = Counter()

    examples = {
        "mixed_model": [],
        "empty_trim_but_trim_in_model": [],
        "good_samples": [],
    }

    for stmt in iter_insert_statements(dump_path, "lots"):
        p = stmt.find("VALUES")
        if p < 0:
            continue

        values_sql = stmt[p + len("VALUES"):].strip()
        if values_sql.endswith(";"):
            values_sql = values_sql[:-1]

        for tup in iter_tuples(values_sql):
            fields = split_fields(tup)
            if len(fields) != col_count:
                continue

            row = {cols[i]: decode_sql_value(fields[i]) for i in range(col_count)}
            if row.get("source") != "encar":
                continue

            metrics["total"] += 1
            if str(row.get("is_active") or "0") == "1":
                metrics["active"] += 1

            model = safe_get(row, "model").strip()
            trim = safe_get(row, "trim").strip()
            make = safe_get(row, "make").strip()

            has_engine = bool(ENGINE_RE.search(model))
            has_drive = bool(DRIVE_RE.search(model))
            has_fuel = bool(FUEL_RE.search(model))
            has_seat = bool(SEAT_RE.search(model))

            if has_engine:
                metrics["model_has_engine_token"] += 1
            if has_drive:
                metrics["model_has_drive_token"] += 1
            if has_fuel:
                metrics["model_has_fuel_token"] += 1
            if has_seat:
                metrics["model_has_seat_token"] += 1

            if not trim:
                metrics["empty_trim"] += 1
                make_empty_trim[make] += 1
                model_empty_trim[(make, model)] += 1

                if any(h in model for h in TRIM_HINTS):
                    metrics["empty_trim_but_trim_hint_in_model"] += 1
                    if len(examples["empty_trim_but_trim_in_model"]) < 80:
                        examples["empty_trim_but_trim_in_model"].append(
                            {"id": row.get("id"), "make": make, "model": model}
                        )

            if trim:
                trim_values[trim] += 1

            gm = GEN_RE.search(model)
            if gm:
                gen = gm.group(1) or gm.group(2)
                if gen:
                    generation_values[gen] += 1

            sm = SEAT_RE.search(model)
            if sm:
                seat_token_values[sm.group(1) + "인승"] += 1

            if (has_engine or has_drive or has_fuel) and len(examples["mixed_model"]) < 120:
                examples["mixed_model"].append(
                    {"id": row.get("id"), "make": make, "model": model, "trim": trim or None}
                )

            if trim and not (has_engine or has_drive or has_fuel) and len(examples["good_samples"]) < 50:
                examples["good_samples"].append(
                    {"id": row.get("id"), "make": make, "model": model, "trim": trim}
                )

    def pct(key: str) -> float:
        total = metrics["total"] or 1
        return round(metrics[key] * 100.0 / total, 2)

    report = {
        "generated_at": datetime.utcnow().isoformat() + "Z",
        "source_scope": "encar_only",
        "totals": {
            "total": metrics["total"],
            "active": metrics["active"],
            "empty_trim": metrics["empty_trim"],
            "empty_trim_pct": pct("empty_trim"),
            "model_has_engine_token": metrics["model_has_engine_token"],
            "model_has_engine_token_pct": pct("model_has_engine_token"),
            "model_has_drive_token": metrics["model_has_drive_token"],
            "model_has_drive_token_pct": pct("model_has_drive_token"),
            "model_has_fuel_token": metrics["model_has_fuel_token"],
            "model_has_fuel_token_pct": pct("model_has_fuel_token"),
            "model_has_seat_token": metrics["model_has_seat_token"],
            "model_has_seat_token_pct": pct("model_has_seat_token"),
            "empty_trim_but_trim_hint_in_model": metrics["empty_trim_but_trim_hint_in_model"],
            "empty_trim_but_trim_hint_in_model_pct_of_empty_trim": round(
                (metrics["empty_trim_but_trim_hint_in_model"] * 100.0 / (metrics["empty_trim"] or 1)), 2
            ),
        },
        "top_empty_trim_models": [
            {"make": mk, "model": md, "count": cnt}
            for (mk, md), cnt in model_empty_trim.most_common(120)
        ],
        "top_makes_empty_trim": [
            {"make": mk, "count": cnt} for mk, cnt in make_empty_trim.most_common(40)
        ],
        "top_trim_values": [
            {"trim": t, "count": c} for t, c in trim_values.most_common(120)
        ],
        "top_generation_tokens_in_model": [
            {"generation_token": g, "count": c} for g, c in generation_values.most_common(80)
        ],
        "top_seat_tokens_in_model": [
            {"seat_token": s, "count": c} for s, c in seat_token_values.most_common(30)
        ],
        "examples": examples,
    }

    os.makedirs(out_dir, exist_ok=True)
    json_path = os.path.join(out_dir, "encar_dump_taxonomy_analysis.json")
    md_path = os.path.join(out_dir, "encar_dump_taxonomy_analysis.md")

    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(report, f, ensure_ascii=False, indent=2)

    t = report["totals"]
    with open(md_path, "w", encoding="utf-8") as f:
        f.write("# Encar Dump Taxonomy Analysis\n\n")
        f.write(f"Generated at: {report['generated_at']}\n\n")
        f.write("## Core Metrics\n")
        for k in [
            "total", "active", "empty_trim", "empty_trim_pct",
            "model_has_engine_token", "model_has_engine_token_pct",
            "model_has_drive_token", "model_has_drive_token_pct",
            "model_has_fuel_token", "model_has_fuel_token_pct",
            "model_has_seat_token", "model_has_seat_token_pct",
            "empty_trim_but_trim_hint_in_model", "empty_trim_but_trim_hint_in_model_pct_of_empty_trim",
        ]:
            f.write(f"- {k}: {t[k]}\n")

        f.write("\n## Top Empty Trim Models (Top 40)\n")
        for r in report["top_empty_trim_models"][:40]:
            f.write(f"- {r['make']} | {r['model']} -> {r['count']}\n")

        f.write("\n## Top Trim Values (Top 40)\n")
        for r in report["top_trim_values"][:40]:
            f.write(f"- {r['trim']} -> {r['count']}\n")

        f.write("\n## Recommendations\n")
        f.write("- Stop writing badge/fuel/drive tokens into `model` for new Encar ingestion.\n")
        f.write("- Add first-class `generation` column and parse from model patterns like `(G30)`, `W213`, `NX4`.\n")
        f.write("- Build one-time backfill command to split mixed `model` into clean `model` + `generation` + inferred trim.\n")
        f.write("- Use confidence-scored rewrite: strict rules auto-apply, uncertain rows flagged for review.\n")

    return {
        "json_report": json_path,
        "md_report": md_path,
        "totals": t,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Analyze Encar taxonomy quality from SQL dump")
    parser.add_argument("--dump", required=True, help="Path to SQL dump file")
    parser.add_argument("--out", default=r"d:\\work\\carbot\\analysis", help="Output directory for reports")
    args = parser.parse_args()

    result = analyze_dump(args.dump, args.out)
    print(json.dumps({"status": "ok", **result}, ensure_ascii=False))


if __name__ == "__main__":
    main()
