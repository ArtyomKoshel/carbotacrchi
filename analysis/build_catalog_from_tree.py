"""
Draft the reference catalog of a make from Encar's tree (scrape_encar_inav_tree.py).

Encar keeps every facelift and powertrain as a model of its own ("쏘나타 디 엣지 하이브리드(DN8)",
2023-04–). Here each Encar model becomes a generation of the reference model, named by what
Encar's own name says and nothing else:

  code   chassis code if Encar writes one ("(DN8)", "그랜저 IG", "LF 쏘나타"), else "Gen N" for
         "N세대", else "I"; plus the facelift words Encar uses, in English ("DN8 · The Edge")
  years  Encar's ModelStartDate / ModelEndDate
  words  what the name says of the engine or body ("Hybrid", "EV", "Coupe"), passed to the
         badge reading (resources/catalog/encar/{make}.json → encar_models)

Words come only from the dictionaries below and resources/catalog/encar/tokens.json; a Korean
word nobody translated stays in the report (and in the name, flagged) — never guessed.

Usage:
  python build_catalog_from_tree.py TREE.json --make 현대 --out ../laravel/resources/catalog [--write]
Without --write it prints the draft and what is unknown.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from collections import defaultdict
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8", errors="replace")

HANGUL = re.compile(r"[가-힣]")

# Encar's make → our make name and file
MAKES = {
    "현대": ("Hyundai", "hyundai"),
    "기아": ("Kia", "kia"),
    "제네시스": ("Genesis", "genesis"),
    "KG모빌리티(쌍용)": ("KG Mobility", "kg-mobility"),
    "쉐보레(GM대우)": ("Chevrolet", "chevrolet"),
    "르노코리아(삼성)": ("Renault Korea", "renault-korea"),
}

# words in Encar model names (longest first is applied): facelift names → kept in the code;
# powertrain and body words → passed to the badge reading
FACELIFT = {
    "디 올 뉴": "The All New", "더 올 뉴": "The All New", "올 뉴": "All New", "All New": "All New",
    "더 뉴": "The New", "베리 뉴": "Very New", "리얼 뉴": "Real New", "어메이징 뉴": "Amazing New",
    "뉴 스타일": "New Style", "뉴 라이즈": "New Rise", "뉴 럭셔리": "New Luxury", "뉴 아레나": "New Arena",
    "뉴 임프레션": "New Impression", "뉴 제너레이션": "New Generation", "뉴 클래식": "New Classic",
    "New Art": "New Art", "뉴": "New", "New": "New",
    "디 엣지": "The Edge", "더 브릴리언트": "The Brilliant", "더 프라임": "The Prime", "더 럭셔리": "The Luxury",
    "더 프레스티지": "The Prestige", "더 넥스트": "The Next", "더 마스터": "The Master", "더 볼드": "The Bold",
    "뷰티풀": "Beautiful", "트랜스폼": "Transform", "(신형)": "(new)", "프리미어": "Premier", "프리미엄": "Premium",
    "이노베이션": "Innovation", "어드밴스": "Advance", "마이스터": "Meister", "네오": "Neo", "노바": "Nova",
    "플래티넘": "Platinum", "크리에이티브": "Creative", "클래식": "Classic", "어반": "Urban", "슈퍼": "Super",
    "(이모션)": "Emotion", "더": "The",
}
# the make's own name inside a model name ("더 뉴 기아 레이") says nothing more
MAKE_WORDS = ["현대", "기아", "제네시스", "쉐보레", "르노", "삼성", "쌍용"]
POWERTRAIN = {
    "플러그인 하이브리드": "Plug-in Hybrid", "하이브리드": "Hybrid", "일렉트리파이드": "Electrified",
    "일렉트릭": "Electric", "EV": "EV", "EVX": "EVX", "Z.E.": "Z.E.",
}
BODY = {
    "슈팅브레이크": "Shooting Brake", "해치백": "Hatchback", "쿠페": "Coupe", "쿱": "Coupe", "세단": "Sedan",
    "살룬": "Saloon", "cw": "Wagon", "왜건": "Wagon", "유로": "Euro", "점보": "Jumbo", "밴": "Van",
}
ROMAN = {"II": 2, "III": 3, "2": 2, "3": 3}
# facelift words that in Korean marketing mean a full model change, a generation of its own
RENEWAL = {"All New", "The All New", "(new)"}


def ym(v: str | None) -> int | None:
    return int(v[:4]) if v and len(v) >= 4 and v[:4].isdigit() else None


def cut(text: str, words: dict[str, str]) -> tuple[str, list[str]]:
    """Cut the dictionary's words out of text, longest first; their English in text order."""
    found: list[tuple[int, str]] = []
    for kr in sorted(words, key=len, reverse=True):
        pattern = r"(?<![\w가-힣])" + re.escape(kr) + r"(?![\w가-힣])" if not kr.startswith("(") else re.escape(kr)
        m = re.search(pattern, text)
        if m:
            found.append((m.start(), words[kr]))
            text = text[: m.start()] + " " * len(m.group(0)) + text[m.end():]
    return re.sub(r"\s+", " ", text).strip(), [w for _, w in sorted(found)]


def read_model(group: str, model_kr: str, splits: dict[str, str]) -> dict:
    """One Encar model → model, code, words, leftover (what nothing understood)."""
    model_name = None
    text = model_kr
    for pattern, name in splits.items():
        m = re.search(pattern, model_kr)
        if m:
            model_name = name
            text = text.replace(m.group(0), " ")  # the split's own words name the model, nothing more
            break
    # a prefix glued to the group's name ("뉴쏘렌토", "뉴QM5"): apart, so the facelift words find it
    text = re.sub(r"([가-힣])(" + re.escape(group) + r")", lambda m: f"{m.group(1)} {m.group(2)}", text)
    code = None
    m = re.search(r"\(([A-Z0-9]{2,5})\)\s*$", text)
    if m:
        code = m.group(1)
        text = text[: m.start()]
    gen = None
    m = re.search(r"(\d)세대", text)
    if m:
        gen = int(m.group(1))
        text = text.replace(m.group(0), " ")
    # the group's own name first, glued or spaced ("뉴쏘렌토", "쏘나타", "볼트 EV"), then the make's
    text = text.replace(group, " ")
    for w in MAKE_WORDS:
        text = re.sub(r"(?<![가-힣])" + w + r"(?![가-힣])", " ", text)
    text, powertrain = cut(text, POWERTRAIN)
    text, body = cut(text, BODY)
    text, facelift = cut(text, FACELIFT)
    text = re.sub(r"\s+", " ", text).strip()
    # a chassis code written as a word ("그랜저 IG", "LF 쏘나타") or a roman / number suffix
    words = text.split()
    rest = []
    for w in words:
        if code is None and re.fullmatch(r"[A-Z]{1,3}\d?", w) and w not in ("R", "X", "W", "C", "H", "R2") and w not in ROMAN:
            code = w
        elif gen is None and w in ROMAN:
            gen = ROMAN[w]
        else:
            rest.append(w)
    label = code or (f"Gen {gen}" if gen else None)
    return {
        "model": model_name,
        "code": label,
        "facelift": " ".join(facelift),
        "words": " ".join(powertrain + body),
        "rest": " ".join(rest),
        "start": None,
    }


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("tree")
    ap.add_argument("--make", required=True, help="Encar's make, e.g. 현대")
    ap.add_argument("--out", default="../laravel/resources/catalog")
    ap.add_argument("--write", action="store_true")
    args = ap.parse_args()

    make_en, slug = MAKES[args.make]
    out = Path(args.out)
    encar_path = out / "encar" / f"{slug}.json"
    encar = json.loads(encar_path.read_text(encoding="utf-8")) if encar_path.exists() else {}
    names = encar.get("names", {})                    # Encar model group → our model
    splits = encar.get("model_splits", {})            # regex on Encar's model → another model of ours
    tree = json.loads(Path(args.tree).read_text(encoding="utf-8"))["taxonomy"]

    models: dict[str, dict] = {}
    for r in tree:
        if r["make_kr"] != args.make or not r.get("model_kr"):
            continue
        key = r["model_kr"]
        cur = models.setdefault(key, {"group": r["model_group_kr"], "start": None, "end": None, "count": 0, "code": r.get("model_code")})
        cur["start"] = cur["start"] or r.get("model_start")
        cur["end"] = cur["end"] or r.get("model_end")
        cur["count"] = max(cur["count"], r.get("model_count") or 0)

    reference: dict[str, dict] = defaultdict(lambda: {"generations": {}})
    encar_models: dict[str, dict] = {}
    unknown = []

    # Encar models of one model, oldest first. A generation is what Encar names by a chassis code
    # or number ("YF 쏘나타", "쏘나타 II"), or the plain model name ("쏘나타", 1985). A name that only
    # adds facelift, powertrain or body words ("쏘나타 더 브릴리언트" 2012, "쏘나타 하이브리드" 2011)
    # belongs to the generation Encar was selling when it came out (YF, 2009–): its start date is
    # Encar's own, so this reads Encar, not the car. "(신형)" is a new model, not a facelift.
    read_all: dict[str, list[tuple[str, dict, dict]]] = defaultdict(list)
    for model_kr, info in models.items():
        read = read_model(info["group"], model_kr, splits)
        model = read["model"] or names.get(info["group"]) or info["group"]
        read_all[model].append((model_kr, info, read))

    placed: list[tuple[str, str, dict, dict]] = []   # model, generation, info, read
    for model, items in read_all.items():
        items.sort(key=lambda x: (ym(x[1]["start"]) or 0, x[0]))
        bases: list[tuple[int, str]] = []               # (start, label) of generations in time order
        for model_kr, info, read in items:
            start = ym(info["start"]) or 0
            facelift, rest = read["facelift"], read["rest"]
            own = read["code"]
            if own is None and facelift in RENEWAL:
                # "올 뉴 투싼" (2015): a model change, named so; "(신형)" says only "new", so its year
                own, facelift = (f"New ({start})" if facelift == "(new)" else facelift), ""
            if own is None and rest:
                own, rest = rest, ""                     # a name of its own ("투싼 ix")
            if own is None and (facelift or read["words"]) and bases:
                base = max((b for b in bases if b[0] <= start), default=bases[0])[1]
            else:
                base = own or "I"
                if base not in {b[1] for b in bases}:
                    bases.append((start, base))
            label = " · ".join(x for x in [base, facelift, rest] if x)
            placed.append((model, label, info, read))

    for model, code, info, read in placed:
        model_kr = next(k for k, v in models.items() if v is info)
        taken = reference[model]["generations"].get(code)
        start = ym(info["start"])
        # one facelift name used twice ("더 뉴 레이" 2017, "더 뉴 기아 레이" 2022) is two facelifts:
        # a facelift label merges only with a model Encar started within a year of it
        if taken and " · " in code and taken["years"][0] and start and abs(taken["years"][0] - start) > 1:
            code = f"{code} ({start})"
        if HANGUL.search(model) or HANGUL.search(code):
            unknown.append((model_kr, model, code))
        g = reference[model]["generations"].setdefault(code, {"code": code, "years": [None, None], "encar": []})
        start, end = ym(info["start"]), ym(info["end"])
        if start and (g["years"][0] is None or start < g["years"][0]):
            g["years"][0] = start
        if info["end"] is None and info["start"]:
            g["open"] = True
        if end and (g["years"][1] is None or end > g["years"][1]):
            g["years"][1] = end
        g["encar"].append(model_kr)
        encar_models[model_kr] = {"model": model, "generation": code, **({"words": read["words"]} if read["words"] else {})}

    for model, data in sorted(reference.items()):
        print(model)
        for g in sorted(data["generations"].values(), key=lambda g: g["years"][0] or 0):
            to = "" if g.get("open") else (g["years"][1] or "")
            print(f"   {g['code']:<34} {g['years'][0] or '?'}–{to}   ← {' | '.join(g['encar'])}")
    if unknown:
        print("\nNot understood (Korean left):")
        for row in unknown:
            print("  ", *row)

    if args.write:
        ref_path = out / "reference" / f"{slug}.json"
        ref = json.loads(ref_path.read_text(encoding="utf-8")) if ref_path.exists() else {"make": make_en, "complectations": [], "editions": [], "models": []}
        # what this script drafted before ("source": "encar") is drafted again from the tree;
        # generations and models a person wrote, and encar_models marked "manual", stay
        for m in ref["models"]:
            m["generations"] = [g for g in m["generations"] if g.get("source") != "encar"]
        have = {m["name"]: m for m in ref["models"]}
        for model, data in reference.items():
            target = have.setdefault(model, {"name": model, "generations": []})
            if target not in ref["models"]:
                ref["models"].append(target)
            codes = {g["code"] for g in target["generations"]}
            for g in sorted(data["generations"].values(), key=lambda g: g["years"][0] or 0):
                if g["code"] not in codes:
                    years = [g["years"][0], None if g.get("open") else g["years"][1]]
                    target["generations"].append({"code": g["code"], "years": years, "source": "encar"})
        ref["models"] = sorted((m for m in ref["models"] if m["generations"] or set(m) - {"name", "generations"}),
                               key=lambda m: m["name"])
        ref_path.write_text(json.dumps(ref, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        encar["korean"] = args.make
        manual = {k: v for k, v in encar.get("encar_models", {}).items() if v.get("manual")}
        encar["encar_models"] = dict(sorted({**encar_models, **manual}.items()))
        encar_path.write_text(json.dumps(encar, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(f"\nwritten: {ref_path}, {encar_path}")


if __name__ == "__main__":
    main()
