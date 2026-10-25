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
# imports (Encar's own make names): drafted by draft_import()
IMPORTS = {
    "테슬라": ("Tesla", "tesla"),
    "지프": ("Jeep", "jeep"),
    "포드": ("Ford", "ford"),
    "렉서스": ("Lexus", "lexus"),
    "도요타": ("Toyota", "toyota"),
    "혼다": ("Honda", "honda"),
    "링컨": ("Lincoln", "lincoln"),
    "캐딜락": ("Cadillac", "cadillac"),
    "BYD": ("BYD", "byd"),
    "닛산": ("Nissan", "nissan"),
    "인피니티": ("Infiniti", "infiniti"),
    "시트로엥/DS": ("Citroën/DS", "citroen-ds"),
    "GMC": ("GMC", "gmc"),
    "닷지": ("Dodge", "dodge"),
    "동풍소콘": ("Dongfeng Sokon", "dongfeng-sokon"),
    "크라이슬러": ("Chrysler", "chrysler"),
    "신위안": ("Xinyuan", "xinyuan"),
    "이네오스": ("Ineos", "ineos"),
    "다이하쯔": ("Daihatsu", "daihatsu"),
    "알파 로메오": ("Alfa Romeo", "alfa-romeo"),
    # Encar's "other": no models, the listings still find the make
    "기타 제조사": ("Other Manufacturers", "other-manufacturers"),
    "기타 수입차": ("Other Imported Cars", "other-imported-cars"),
    # a second Encar make of a reference make: its own Encar file names the make, the reference
    # file is the make's (written by hand here, never by this script)
    "쉐보레": ("Chevrolet", "chevrolet-imported"),
}
SECOND = {"쉐보레": "chevrolet"}
MAKES.update(IMPORTS)

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
    "오픈베드": "Open Bed",
}
# a number after the name is Encar's ("카렌스 II" 2002 is a facelift of the first Carens), kept as
# written; only "N세대" says generation
ROMAN = {"II": "II", "III": "III", "2": "II", "3": "III"}
# facelift words that in Korean marketing mean a full model change, a generation of its own
# ("더 K9" 2018 is THE K9, the second K9; "더 뉴" is the facelift)
RENEWAL = {"All New", "The All New", "(new)", "The"}


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
        gen = f"Gen {m.group(1)}"
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
    label = code or gen
    return {
        "model": model_name,
        "code": label,
        "facelift": " ".join(facelift),
        "words": " ".join(powertrain + body),
        "rest": " ".join(rest),
        "start": None,
    }


def badge_groups(tree: list[dict], make: str) -> dict[str, dict[str, str]]:
    """Encar model → badge → its BadgeGroup, where Encar files the badge under one group only.

    The collector gets no BadgeGroup, and a Korean badge is often the complectation alone
    ("더 뉴 레이" / "프레스티지"): its engine is the group Encar's tree puts it in ("가솔린 1000cc").
    A badge under two groups stays out — which one a car is, Encar did not say.
    """
    seen: dict[tuple[str, str], set[str]] = defaultdict(set)
    for r in tree:
        if r["make_kr"] == make and r.get("badge_kr") and r.get("badge_group_kr"):
            seen[(r["model_kr"], r["badge_kr"])].add(r["badge_group_kr"])
    out: dict[str, dict[str, str]] = defaultdict(dict)
    for (model, badge), groups in sorted(seen.items()):
        if len(groups) == 1:
            out[model][badge] = next(iter(groups))
    return dict(out)


ROMAN_N = ["", "I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX", "X", "XI", "XII", "XIII", "XIV", "XV"]
# an import's facelift words before the name ("뉴 MKZ", "올뉴어코드", not "뉴요커"): Encar's, they name no generation
IMPORT_PREFIX = re.compile(r"^(?:(?:디 올 뉴|더 올 뉴|올 뉴|더 뉴|뉴|All New|New)\s+|올뉴)")
# a BadgeDetail that is a generation: "5세대", or a chassis code ("WK2", "XV60", "L33", "Z34", "FD2/FN2")
GEN_DETAIL = re.compile(r"^(?:(\d{1,2})(?:세대|st|nd|rd|th)|([A-Z]{1,3}\d{1,3}[A-Z]?\d?(?:/[A-Z]{1,3}\d{1,3})?|[A-Z]{2}))$")


def gen_label(detail: str) -> tuple[str, int | None] | None:
    """A generation-like BadgeDetail → (code, number): "5세대" → ("V", 5), "WK2" → ("WK2", None)."""
    m = GEN_DETAIL.match(detail.strip())
    if not m:
        return None
    if m.group(1):
        n = int(m.group(1))
        return (ROMAN_N[n] if n < len(ROMAN_N) else f"Gen {n}", n)
    return (m.group(2) or m.group(0), None)


def draft_import(tree: list[dict], make: str, encar: dict, written: dict[str, int]):
    """An import make: Encar keeps one model per generation it names ("익스플로러 6세대", "랭글러 (JL)")
    and lumps the older ones under the plain name ("익스플로러" 1991–2019), each listing's generation
    in its BadgeDetail ("5세대", "WK2"). The badge holds engine and package, as with the European makes.

      model       names[name] (the Encar model without chassis, number and facelift words), else
                  names[ModelGroup]; a name glued to its group ("ES300h" in "ES", "QX56" in "QX")
                  is the group's model, the name its engine (passed to the badge reading)
      generation  "(JL)" → JL, "6세대" → VI (number 6); else, where the tree's BadgeDetails name
                  generations, none (the listing's BadgeDetail says it); else nothing is assumed:
                  the model is reported, a person decides (encar_models "manual": true)

    written: the generations a person wrote per model (the reference file's, not "source": "encar").
    Returns reference {model: {"generations": {code: {...}}}}, encar_models, the report.
    """
    names = encar.get("names", {})
    manual = {k: v for k, v in encar.get("encar_models", {}).items() if v.get("manual")}
    models: dict[str, dict] = {}
    details: dict[str, set] = defaultdict(set)
    for r in tree:
        if r["make_kr"] != make or not r.get("model_kr") or r["model_group_kr"] == "기타":
            continue
        cur = models.setdefault(r["model_kr"], {"group": r["model_group_kr"], "start": r.get("model_start"),
                                                "end": r.get("model_end"), "count": r.get("model_count") or 0})
        cur["count"] = max(cur["count"], r.get("model_count") or 0)
        if r.get("badge_detail_kr"):
            details[r["model_kr"]].add(r["badge_detail_kr"])

    reference: dict[str, dict] = defaultdict(lambda: {"generations": {}})
    encar_models: dict[str, dict] = {}
    report: list[str] = []
    plain: dict[str, list[str]] = defaultdict(list)    # reference model → Encar models naming no generation

    def put(model: str, code: str, number: int | None, years: list, model_kr: str | None) -> None:
        g = reference[model]["generations"].setdefault(code, {"code": code, "years": [None, None], "encar": []})
        if number:
            g["number"] = number
        if years[0] and (g["years"][0] is None or years[0] < g["years"][0]):
            g["years"][0] = years[0]
        if years[1] is None and years[0]:
            g["open"] = True
        if years[1] and (g["years"][1] is None or years[1] > g["years"][1]):
            g["years"][1] = years[1]
        if model_kr:
            g["encar"].append(model_kr)

    for model_kr, info in sorted(models.items(), key=lambda x: (x[1]["start"] or "", x[0])):
        text = model_kr.strip()
        code = number = None
        m = re.search(r"\s*\(([A-Z0-9]{2,5})\)\s*$", text)
        if m:
            code, text = m.group(1), text[: m.start()]
        m = re.search(r"\s+(\d{1,2})세대$", text)
        if m:
            number = int(m.group(1))
            code, text = ROMAN_N[number], text[: m.start()]
        base = IMPORT_PREFIX.sub("", text).strip()
        group = info["group"].strip()
        words = ""
        if base in names:
            model = names[base]
        elif base == group:
            model = names.get(group, group)
        elif re.fullmatch(r"[A-Za-z0-9-]+", group) and re.fullmatch(re.escape(group) + r" ?[A-Za-z0-9+\-]+", base):
            model, words = names.get(group, group), base
        else:
            model = names.get(base, base)
        if HANGUL.search(model):
            report.append(f"name not translated: {model_kr} (group {group})")
        years = [ym(info["start"]), ym(info["end"])]
        entry = {"model": model, **({"words": words} if words else {})}
        if model_kr in manual:
            encar_models[model_kr] = manual[model_kr]
            if manual[model_kr].get("generation") is None:
                plain[manual[model_kr]["model"]].append(model_kr)
            continue
        if code:
            put(model, code, number, years, model_kr)
            encar_models[model_kr] = {**entry, "generation": code}
            continue
        gens = [g for g in (gen_label(d) for d in details[model_kr]) if g]
        for c, n in gens:
            put(model, c, n, [None, None], None)
        if not gens and info["count"]:
            report.append(f"no generation named: {model_kr} {years[0]}–{years[1] or ''} ({info['count']} listings) → decide by hand")
        plain[model].append(model_kr)
        encar_models[model_kr] = entry

    # a model of one generation answers for a listing that names none (Reference::generation):
    # an Encar model without one must not fall into it
    for model, kr in plain.items():
        drafted = [c for c, g in reference[model]["generations"].items()]
        if written.get(model, 0) + len(drafted) == 1:
            report.append(f"one generation: {model} ({''.join(drafted) or 'written'}) takes {' | '.join(kr)}, which name none"
                          " — right only if the model never had another")
    return reference, encar_models, report


def print_and_write(args, out: Path, make_en: str, slug: str, encar: dict, encar_path: Path, tree: list[dict],
                    reference: dict, encar_models: dict, report: list[str]) -> None:
    for model, data in sorted(reference.items()):
        print(model)
        for g in sorted(data["generations"].values(), key=lambda g: g["years"][0] or 0):
            to = "" if g.get("open") else (g["years"][1] or "")
            print(f"   {g['code']:<34} {g['years'][0] or '?'}–{to}   ← {' | '.join(g['encar'])}")
    if report:
        print("\nFor a person:")
        for line in report:
            print("  ", line)

    if args.write and args.make in SECOND:
        encar = {"make": make_en, **encar, "korean": args.make}
        manual = {k: v for k, v in encar.get("encar_models", {}).items() if v.get("manual")}
        encar["encar_models"] = dict(sorted({**encar_models, **manual}.items()))
        encar["badge_groups"] = badge_groups(tree, args.make)
        encar_path.write_text(json.dumps(encar, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(f"\nwritten: {encar_path} (the reference is reference/{SECOND[args.make]}.json, by hand)")
    elif args.write:
        ref_path = out / "reference" / f"{slug}.json"
        ref = json.loads(ref_path.read_text(encoding="utf-8")) if ref_path.exists() else {"make": make_en, "complectations": [], "editions": [], "models": []}
        # what this script drafted before ("source": "encar") is drafted again from the tree;
        # generations and models a person wrote, and encar_models marked "manual", stay
        hand = {m["name"] for m in ref["models"] if not any(g.get("source") == "encar" for g in m["generations"])}
        for m in ref["models"]:
            m["generations"] = [g for g in m["generations"] if g.get("source") != "encar"]
        have = {m["name"]: m for m in ref["models"]}
        for model, data in reference.items():
            target = have.setdefault(model, {"name": model, "generations": []})
            if target not in ref["models"]:
                ref["models"].append(target)
            codes = {c.upper() for g in target["generations"] for c in [g["code"], *g.get("aliases", [])]}
            numbers = {g["number"] for g in target["generations"] if "number" in g}
            for g in sorted(data["generations"].values(), key=lambda g: g["years"][0] or 0):
                if g["code"].upper() not in codes and g.get("number", 0) not in numbers:
                    years = [g["years"][0], None if g.get("open") else g["years"][1]]
                    number = {"number": g["number"]} if g.get("number") else {}
                    target["generations"].append({"code": g["code"], **number, "years": years, "source": "encar"})
        # an import's model Encar sells without naming a generation stays, so its listings find the
        # model; so does a model a person wrote
        ref["models"] = sorted((m for m in ref["models"] if m["generations"] or set(m) - {"name", "generations"}
                                or m["name"] in reference or m["name"] in hand),
                               key=lambda m: m["name"])
        ref_path.write_text(json.dumps(ref, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        # a make Encar files under two names keeps both ("쉐보레(GM대우)", "쉐보레")
        if args.make not in (encar.get("korean") if isinstance(encar.get("korean"), list) else []):
            encar["korean"] = args.make
        manual = {k: v for k, v in encar.get("encar_models", {}).items() if v.get("manual")}
        encar["encar_models"] = dict(sorted({**encar_models, **manual}.items()))
        encar["badge_groups"] = badge_groups(tree, args.make)
        encar_path.write_text(json.dumps(encar, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(f"\nwritten: {ref_path}, {encar_path}")


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
    if args.make in IMPORTS:
        ref_path = out / "reference" / f"{SECOND.get(args.make, slug)}.json"
        written = {m["name"]: sum(1 for g in m["generations"] if g.get("source") != "encar")
                   for m in (json.loads(ref_path.read_text(encoding="utf-8"))["models"] if ref_path.exists() else [])}
        reference, encar_models, report = draft_import(tree, args.make, encar, written)
        print_and_write(args, out, make_en, slug, encar, encar_path, tree, reference, encar_models, report)
        return

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

    # a person's word on an Encar model ("New 스포티지" 2004 is the second Sportage, where "New"
    # elsewhere is a facelift): its generation is taken as it stands and starts a generation
    manual = {k: v for k, v in encar.get("encar_models", {}).items() if v.get("manual")}

    placed: list[tuple[str, str, dict, dict]] = []   # model, generation, info, read
    for model, items in read_all.items():
        items.sort(key=lambda x: (x[1]["start"] or "", x[0]))
        # generations in time order: label → [first year, last year or None while Encar sells it];
        # a facelift or powertrain added to one extends it ("그랜저 TG" 2005–2008, "뉴 럭셔리" 2008–2009,
        # "더 럭셔리" 2009–2011 are all TG)
        bases: dict[str, list] = {}

        def selling(year: int) -> str | None:
            on = [(span[0], label) for label, span in bases.items()
                  if span[0] <= year and (span[1] is None or year <= span[1] + 2)]
            return max(on)[1] if on else None

        def add(label: str, info: dict, base: bool) -> None:
            first, last = ym(info["start"]) or 0, None if info["end"] is None else ym(info["end"])
            span = bases.get(label.split(" · ")[0])
            if span is None and base:
                bases[label] = [first, last]
            elif span is not None and span[1] is not None:
                span[1] = None if last is None else max(span[1], last)

        for model_kr, info, read in items:
            start = ym(info["start"]) or 0
            facelift, rest = read["facelift"], read["rest"]
            own = read["code"]
            if model_kr in manual:
                label = manual[model_kr]["generation"]
                add(label, info, " · " not in label)
                placed.append((model, label, info, read))
                continue
            if facelift in RENEWAL:
                # "올 뉴 투싼" (2015): a model change, named so; "(신형)" says only "new", so its year;
                # with a code ("올 뉴 모닝 (JA)") the code names it
                renewal = {"(new)": f"New ({start})", "The": f"The {model}"}.get(facelift, facelift)
                own, facelift = own or renewal, ""
            if own is None and rest:
                own, rest = rest, ""                     # a name of its own ("투싼 ix")
            base = own
            if base is None and (facelift or read["words"]):
                # the generation on sale when it came out; none on sale ("뷰티풀 코란도" 2019, the
                # last Korando ended 2005): Encar's name for it is a generation of its own
                base = selling(start)
                if base is None and facelift:
                    base, facelift = facelift, ""
            base = base or "I"
            add(base, info, True)
            label = " · ".join(x for x in [base, facelift, rest] if x)
            placed.append((model, label, info, read))

    for model, code, info, read in placed:
        model_kr = next(k for k, v in models.items() if v is info)
        taken = reference[model]["generations"].get(code)
        start = ym(info["start"])
        # one facelift name used twice ("더 뉴 레이" 2017, "더 뉴 기아 레이" 2022) is two facelifts:
        # a facelift label merges only with a model Encar started within a year of it
        if taken and " · " in code and taken["years"][0] and start and abs(taken["years"][0] - start) > 1:
            # the later one, or a powertrain of it ("더 뉴 기아 레이 EV" 2023 joins 2022's)
            code = next((c for c, g in reference[model]["generations"].items()
                         if c.startswith(f"{code} (") and g["years"][0] and abs(g["years"][0] - start) <= 1),
                        f"{code} ({start})")
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

    print_and_write(args, out, make_en, slug, encar, encar_path, tree, reference, encar_models,
                    [f"Korean left: {' '.join(row)}" for row in unknown])


if __name__ == "__main__":
    main()
