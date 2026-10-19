"""
Compact photo / option storage (parser/lot_compact.py).
Usage: python -m pytest parser/tests/test_lot_compact.py -v
"""
from __future__ import annotations

import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from lot_compact import OptionBits, decode_photos, encode_photos
from models import CarLot

Q = "impolicy=heightRate&rh=480&cw=640&ch=480&cg=Center"


def _url(code: str, base: str = "38320776", d: str = "02", ext: str = "jpg") -> str:
    return f"https://ci.encar.com/carpicture{d}/pic{base[:4]}/{base}_{code}.{ext}?{Q}"


def test_template_roundtrip_keeps_order_and_base():
    urls = [_url(c) for c in ("015", "001", "009")]
    cols = encode_photos(urls)
    assert cols == {"photo_dir": 2, "photo_base_id": 38320776, "photo_codes": "015,001,009",
                    "photo_ext_upper": 0, "photo_urls": None}
    assert decode_photos(cols) == urls


def test_upper_case_extension():
    urls = [_url(c, ext="JPG") for c in ("001", "002")]
    cols = encode_photos(urls)
    assert cols["photo_ext_upper"] == 1
    assert decode_photos(cols) == urls


def test_off_template_lots_are_stored_verbatim():
    cases = [
        [_url("001"), _url("002", ext="JPG")],             # mixed extension
        [_url("001"), _url("002", d="03")],                # mixed dir
        [_url("001"), _url("002", base="38320777")],       # mixed base
        [_url("001").replace("pic3832", "pic3833")],       # pic prefix != base[:4]
        [_url("001").replace("&cg=Center", "")],           # other resize policy
        ["https://img.kbchachacha.com/IMG/carimg/l/img01/1.jpg"],
    ]
    for urls in cases:
        cols = encode_photos(urls)
        assert cols["photo_codes"] == "" and cols["photo_dir"] is None
        assert json.loads(cols["photo_urls"]) == urls
        assert decode_photos(cols) == urls


def test_no_photos_means_unknown():
    for urls in (None, []):
        cols = encode_photos(urls)
        assert cols["photo_codes"] is None and cols["photo_urls"] is None
        assert decode_photos(cols) == []


def test_to_db_row_carries_photo_columns():
    lot = CarLot(id="1", source="encar", make="a", model="b", year=2020, price=1)
    assert lot.to_db_row()["photo_codes"] is None
    lot.photos = [_url("001")]
    assert lot.to_db_row()["photo_codes"] == "001"


class _Cursor:
    def __init__(self, rows):
        self.rows = rows

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False

    def execute(self, *a):
        pass

    def fetchall(self):
        return self.rows


class _Conn:
    def __init__(self, rows):
        self.rows = rows
        self.loads = 0

    def cursor(self):
        self.loads += 1
        return _Cursor(self.rows)


def test_option_mask():
    conn = _Conn([{"code": "001", "bit": 0}, {"code": "003", "bit": 2}, {"code": "099", "bit": 63},
                  {"code": "100", "bit": 64}])
    bits = OptionBits()
    assert bits.mask(conn, None) is None
    assert bits.mask(conn, []) is None
    assert bits.mask(conn, ["001", "003", "003"]) == 0b101
    assert bits.mask(conn, json.dumps(["001"])) == 1
    assert bits.mask(conn, ["099"]) == 1 << 63
    assert bits.mask(conn, ["100", "777"]) == 0  # beyond 64 bits / unknown: JSON-only
    assert conn.loads == 1  # unknown code doesn't reload before the throttle interval
