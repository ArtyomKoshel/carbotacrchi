"""
Compact on-row storage for lot photos and option codes.

Both used to live in pivot tables (`lot_photos`, `lot_options`) that grew to
~2 GB each. They are now stored as a handful of columns on `lots`:

Photos
    Every Encar photo URL follows one template:
        https://ci.encar.com/carpicture{dir}/pic{base[:4]}/{base}_{code}.{ext}?impolicy=...
    `dir` and `base` are constant within a lot (`base` is NOT always the lot id:
    relists keep the old photo id), so a lot's gallery is stored as
        photo_dir        TINYINT   (1..99, "carpicture07" -> 7)
        photo_base_id    INT       (e.g. 38320776)
        photo_codes      VARCHAR   ordered codes, e.g. "021,001,009"
        photo_ext_upper  BOOL      ".JPG" instead of ".jpg"
    Anything that does not fit the template (KBChacha, a changed CDN, mixed
    dirs) is stored verbatim in `photo_urls` (JSON array) with
    photo_codes = '' — so `photo_codes IS NOT NULL` always means "photos known".

    The URL builder must stay in sync with laravel/app/Support/LotPhotos.php.

Options
    Each code in `encar_option_catalog` has a stable `bit` (0..63 fit into
    `lots.options_mask`). Search filters with `(options_mask & m) = m`.
    Codes without a bit < 64 are still kept in `lots.options` JSON and the
    search falls back to `MEMBER OF` for them. Must stay in sync with
    laravel/app/Support/OptionBits.php.
"""
from __future__ import annotations

import json
import re
import time as _time

PHOTO_QUERY = "impolicy=heightRate&rh=480&cw=640&ch=480&cg=Center"

_PHOTO_RE = re.compile(
    r"^https://ci\.encar\.com/carpicture(\d{2})/pic(\d{4})/([1-9]\d{3,9})_(\d{3})\.(jpg|JPG)"
    r"\?" + re.escape(PHOTO_QUERY) + r"$"
)

_INT_UNSIGNED_MAX = 4_294_967_295
MASK_BITS = 64

PHOTO_COLUMNS = ("photo_dir", "photo_base_id", "photo_codes", "photo_ext_upper", "photo_urls")


def photo_url(photo_dir: int, base_id: int, code: str, ext_upper: bool = False) -> str:
    base = str(base_id)
    ext = "JPG" if ext_upper else "jpg"
    return f"https://ci.encar.com/carpicture{photo_dir:02d}/pic{base[:4]}/{base}_{code}.{ext}?{PHOTO_QUERY}"


def encode_photos(urls: list[str] | None) -> dict:
    """Ordered photo URLs -> lots.photo_* column values.

    Returns all-None columns when there are no photos (callers treat
    photo_codes=None as "photos unknown, keep what is stored").
    """
    empty = {**dict.fromkeys(PHOTO_COLUMNS), "photo_ext_upper": 0}
    if not urls:
        return empty

    dirs, bases, exts, codes = set(), set(), set(), []
    for url in urls:
        m = _PHOTO_RE.match(url or "")
        if not m or m.group(2) != m.group(3)[:4]:
            break
        dirs.add(int(m.group(1)))
        bases.add(int(m.group(3)))
        exts.add(m.group(5))
        codes.append(m.group(4))
    else:
        if (len(dirs) == 1 and len(bases) == 1 and len(exts) == 1
                and 1 <= next(iter(dirs)) <= 99
                and next(iter(bases)) <= _INT_UNSIGNED_MAX):
            return {
                "photo_dir": next(iter(dirs)),
                "photo_base_id": next(iter(bases)),
                "photo_codes": ",".join(codes),
                "photo_ext_upper": 1 if exts == {"JPG"} else 0,
                "photo_urls": None,
            }

    return {**empty, "photo_codes": "", "photo_ext_upper": 0,
            "photo_urls": json.dumps(list(urls), ensure_ascii=False)}


def decode_photos(row: dict) -> list[str]:
    """lots.photo_* column values -> ordered photo URLs."""
    if row.get("photo_urls"):
        urls = row["photo_urls"]
        return json.loads(urls) if isinstance(urls, str) else list(urls)
    codes = row.get("photo_codes")
    if not codes:
        return []
    return [
        photo_url(int(row["photo_dir"]), int(row["photo_base_id"]), code, bool(row.get("photo_ext_upper")))
        for code in codes.split(",")
    ]


class OptionBits:
    """code -> bit map backed by encar_option_catalog.bit.

    Bits are assigned by Laravel (migration / `options:import-encar`), which
    also backfills the mask for a newly assigned bit. A code without a bit is
    left out of the mask; it stays in `lots.options` and search falls back to
    JSON for it. The map is re-read when an unknown code shows up (throttled),
    so a freshly imported code is picked up without a parser restart.
    """

    _RELOAD_INTERVAL = 300.0

    def __init__(self) -> None:
        self._bits: dict[str, int] | None = None
        self._loaded_at = 0.0

    def _load(self, conn) -> None:
        with conn.cursor() as cur:
            cur.execute("SELECT code, bit FROM encar_option_catalog WHERE bit IS NOT NULL")
            rows = cur.fetchall()
        self._bits = {
            str(r["code"] if isinstance(r, dict) else r[0]): int(r["bit"] if isinstance(r, dict) else r[1])
            for r in rows
        }
        self._loaded_at = _time.monotonic()

    def mask(self, conn, codes) -> int | None:
        """Bitmask for a lot's option codes; None when the lot has no options list."""
        if isinstance(codes, str):
            try:
                codes = json.loads(codes)
            except Exception:
                codes = None
        if not isinstance(codes, list) or not codes:
            return None
        if self._bits is None:
            self._load(conn)

        codes = {str(c).strip() for c in codes} - {""}
        if any(c not in self._bits for c in codes)                 and _time.monotonic() - self._loaded_at > self._RELOAD_INTERVAL:
            self._load(conn)

        mask = 0
        for code in codes:
            bit = self._bits.get(code)
            if bit is not None and bit < MASK_BITS:
                mask |= 1 << bit
        return mask
