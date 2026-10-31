from __future__ import annotations

import asyncio
import json
from datetime import UTC, datetime
from pathlib import Path
from typing import Any
from urllib.parse import quote

import pytest

from collector.adapters.che168.api import (
    DETAIL_URL,
    Che168Api,
    CollectionError,
    Reply,
    chaboshi_link,
)
from collector.adapters.che168.chaboshi import chaboshi_report, inspection
from collector.adapters.che168.job import Che168Run, run_che168
from collector.adapters.che168.mapping import stored_card, to_lot
from collector.adapters.che168.signing import Signer
from collector.ports import MemoryHistoryStore, MemorySink
from tests.test_che168 import detail
from tests.test_che168_job import Queue, Site, job

NOW = datetime(2026, 10, 30, tzinfo=UTC)
FIXTURES = Path(__file__).parent / "fixtures" / "chaboshi"
FUEL_PAGE = "https://api.chaboshi.cn/insurance/report?orderNo=o1&signature=s&userid=1"
EV_PAGE = "https://api.chaboshi.cn/platform/v2/insurance/auth_report_h5?orderno=o2&signature=s"
PAGE_HTML = (
    b"<html><script>window.__INIT_PARAMS__ = {reportVersion: '1.0.2',orderNo: 'o1',orderno: 'o1',"
    b"signature: 's',signparamname: 'orderNo',sign: 'k',type: '1',userid: '1'};</script></html>"
)


def fixture(name: str) -> dict[str, Any]:
    data: dict[str, Any] = json.loads((FIXTURES / f"{name}.json").read_text(encoding="utf-8"))
    return data


def app_link(url: str) -> str:
    """The card's cxc.reporturl: an app link carrying the https page."""
    return "usedcar://scheme.platform/webview?param=" + quote(json.dumps({"url": url}))


def test_link_is_the_chaboshi_page_of_the_app_link_and_nothing_else() -> None:
    assert chaboshi_link({"reporturl": app_link(FUEL_PAGE)}) == FUEL_PAGE
    assert chaboshi_link({"reporturl": EV_PAGE}) == EV_PAGE
    assert (
        chaboshi_link({"reporturl": app_link("https://car-inspection.guazi.com/detection/check-report")})
        is None
    )
    assert chaboshi_link({"reporturl": "https://api.chaboshi.cn.evil.com/insurance/report"}) is None
    assert chaboshi_link({"reporturl": "http://api.chaboshi.cn/insurance/report"}) is None
    assert chaboshi_link({"reporturl": "usedcar://x?param=notjson"}) is None
    assert chaboshi_link({"reporturl": ""}) is None
    assert chaboshi_link(None) is None


class Chaboshi:
    """The report page and its data request; `code` and `body` say what the data request answers."""

    def __init__(self, report: dict[str, Any], code: int = 0) -> None:
        self.report, self.code = report, code
        self.posts: list[tuple[str, dict[str, str], dict[str, str]]] = []

    def get(self, url: str, params: dict[str, str]) -> Reply:
        if url.startswith("https://api.chaboshi.cn/"):
            return Reply(200, PAGE_HTML)
        if url == "https://oss.chaboshi.cn/report.json":
            return Reply(200, json.dumps(self.report["data"]).encode())
        raise AssertionError(url)

    def post(self, url: str, data: dict[str, str], headers: dict[str, str]) -> Reply:
        self.posts.append((url, data, headers))
        if self.code == 5:
            return Reply(200, json.dumps({"code": 5, "data": "https://oss.chaboshi.cn/report.json"}).encode())
        return Reply(200, json.dumps({**self.report, "code": self.code}).encode())


def api_for(site: Chaboshi) -> Che168Api:
    api = Che168Api(site.get, Signer.bundled(), delay=0)
    api.post = site.post
    return api


def test_the_report_comes_from_the_request_the_page_itself_makes() -> None:
    site = Chaboshi(fixture("fuel"))
    kind, info = api_for(site).chaboshi(FUEL_PAGE)
    assert kind == "fuel"
    assert info["reportLevel"] == "S"
    url, data, headers = site.posts[0]
    assert url.endswith("/wap/insurance/getDetectionFuelVehicleReportData")
    assert data["orderNo"] == "o1"
    assert "orderno" not in data  # the page sends one of the two
    assert (headers["Sign"], headers["signparamname"]) == ("k", "orderNo")


def test_an_ev_report_and_one_given_as_a_file() -> None:
    site = Chaboshi(fixture("ev"), code=5)
    kind, info = api_for(site).chaboshi(EV_PAGE)
    assert kind == "ev"
    assert info["batteryHealthScore"] == 93.6
    assert site.posts[0][0].endswith("/getDetectionNewEnergyReportData")


@pytest.mark.parametrize(
    ("code", "page", "reason"),
    [(1, PAGE_HTML, "report_rejected"), (0, b"<html>no params</html>", "report_schema_changed")],
)
def test_a_refused_or_changed_report(code: int, page: bytes, reason: str) -> None:
    site = Chaboshi(fixture("fuel"), code=code)
    api = api_for(site)
    api.request = lambda url, params: Reply(200, page)
    with pytest.raises(CollectionError, match=reason):
        api.chaboshi(FUEL_PAGE)


def test_without_a_post_transport_there_is_no_report() -> None:
    api = Che168Api(Chaboshi(fixture("fuel")).get, Signer.bundled(), delay=0)
    with pytest.raises(CollectionError, match="post_unavailable"):
        api.chaboshi(FUEL_PAGE)


def test_fuel_report_keeps_the_verdicts_the_findings_and_no_person() -> None:
    report = chaboshi_report("fuel", fixture("fuel")["data"]["reportInfo"])
    assert (report["grade"], report["inspected_on"], report["product"]) == ("S", "2026-10-27", "事故排查检测")
    assert (report["major_accident"], report["flood"], report["fire"]) == (False, False, False)
    systems = {s["name"]: s for s in report["systems"]}
    assert systems["事故车检测"]["checked"] == 70
    assert systems["事故车检测"]["findings"] == []
    assert systems["事故车检测"]["unchecked"] == ["底板横梁", "底板纵梁", "车身底板"]
    extra = systems["附加检测项"]["findings"]
    assert [(f["part"], f["notes"]) for f in extra] == [
        ("变速箱总成", [["漏油", None]]),
        ("发动机总成", [["渗漏痕迹", None]]),
    ]
    assert "battery" not in report
    dumped = json.dumps(report, ensure_ascii=False)
    for personal in ("TESTVIN", "13800000000", "inspector", "address"):
        assert personal not in dumped


def test_ev_report_has_its_battery_and_water_traces_that_are_not_a_flood() -> None:
    report = chaboshi_report("ev", fixture("ev")["data"]["reportInfo"])
    assert report["flood"] is False
    water = {s["name"]: s for s in report["systems"]}["泡水车检测"]["findings"]
    assert ["水痕", "内侧水痕"] in water[0]["notes"]  # traces of water, below Chaboshi's flood standard
    assert report["mileage_km"] == 49619
    battery = report["battery"]
    assert (battery["soh"], battery["data_date"], battery["type"]) == (93.6, "2026-10-27", "磷酸铁锂")
    assert battery["capacity_kwh"] == 60.48
    assert battery["fast_charge_soc"] == "30-80"
    assert len(battery["ranges"]) == 3


def test_the_inspection_says_what_chaboshi_found_not_an_insurance_history() -> None:
    ev = inspection(chaboshi_report("ev", fixture("ev")["data"]["reportInfo"]))
    assert (ev.has_accident, ev.has_flood, ev.has_fire) == (False, False, False)
    assert ev.has_outer_damage is True  # the accident check marked repaired panels
    assert str(ev.inspected_on) == "2026-10-28"
    assert ev.mileage_km == 49619
    assert ev.cert_no == "TEST0001"
    assert ev.report_url is None  # a signed link is never kept
    assert ev.details["chaboshi"]["battery"]["soh"] == 93.6
    fuel = inspection(chaboshi_report("fuel", fixture("fuel")["data"]["reportInfo"]))
    assert fuel.has_outer_damage is False


def test_a_lot_carries_the_report_as_its_inspection_and_the_store_keeps_it() -> None:
    report = chaboshi_report("fuel", fixture("fuel")["data"]["reportInfo"])
    data = {**detail("5"), "report": report, "report_link": True}
    lot = to_lot(data, NOW)
    assert lot.inspection is not None
    assert lot.inspection.details["chaboshi"]["grade"] == "S"
    assert lot.history.has_accident is None  # no insurance history from an inspection
    card = stored_card(data, NOW)
    assert card["report"] == report
    assert card["report_link"] is True
    assert to_lot(card, NOW).inspection == lot.inspection
    assert to_lot(detail("6"), NOW).inspection is None


class ReportSite(Site):
    """Every card links a fuel report; `report_down` makes the data request fail."""

    def __init__(self) -> None:
        super().__init__()
        self.chaboshi = Chaboshi(fixture("fuel"))
        self.report_down = False

    def request(self, url: str, params: dict[str, str]) -> Reply:
        if url.startswith("https://api.chaboshi.cn/"):
            return self.chaboshi.get(url, params)
        reply = super().request(url, params)
        if url == DETAIL_URL:
            body = json.loads(reply.body)
            body["result"]["cxc"] = {"reporturl": app_link(FUEL_PAGE)}
            return Reply(reply.status, json.dumps(body).encode())
        return reply

    def post(self, url: str, data: dict[str, str], headers: dict[str, str]) -> Reply:
        return Reply(503, b"") if self.report_down else self.chaboshi.post(url, data, headers)


def report_run(site: ReportSite, store: MemoryHistoryStore) -> tuple[MemorySink, Queue]:
    api = Che168Api(site.request, Signer.bundled(), delay=0)
    api.post = site.post
    sink, queue = MemorySink(), Queue()
    run = Che168Run(
        job=job(), queue=queue, sink=sink, api=api,  # type: ignore[arg-type]
        max_pages=10, max_cars=100, clock=lambda: NOW, history=store,
    )  # fmt: skip
    assert asyncio.run(run_che168(run)) == "done"
    return sink, queue


def test_a_run_fetches_the_linked_report_and_a_failed_one_is_fetched_next_time() -> None:
    site, store = ReportSite(), MemoryHistoryStore()
    site.report_down = True
    sink, queue = report_run(site, store)
    assert len(sink.lots) == 3  # the cars stay
    assert all(lot.inspection is None for lot in sink.lots)
    assert queue.finished is not None
    assert (queue.finished[1]["reports"], queue.finished[1]["reports_failed"]) == (0, 3)

    site.report_down = False
    site.details.clear()
    sink, queue = report_run(site, store)
    assert sorted(site.details) == ["1", "2", "3"]  # stored without the linked report: fetched again
    assert all(lot.inspection and lot.inspection.details["chaboshi"]["grade"] == "S" for lot in sink.lots)
    assert queue.finished is not None
    assert queue.finished[1]["reports"] == 3

    site.details.clear()
    report_run(site, store)
    assert site.details == []  # now the store has the cards with their reports


@pytest.mark.parametrize("kind", ["fuel", "ev"])
def test_the_core_reads_the_report_as_the_collector_writes_it(kind: str) -> None:
    """contracts/fixtures/chaboshi_*.json is details.chaboshi for the core's tests: it must not drift."""
    shared = Path(__file__).parents[2] / "contracts" / "fixtures" / f"chaboshi_{kind}.json"
    written = chaboshi_report(kind, fixture(kind)["data"]["reportInfo"])
    assert json.loads(shared.read_text(encoding="utf-8")) == written


def test_a_link_chaboshi_refuses_is_kept_as_unavailable_and_not_tried_again() -> None:
    site, store = ReportSite(), MemoryHistoryStore()
    refusal = json.dumps({"Code": "1001", "Message": "签名错误"}).encode()
    site.chaboshi.get = lambda url, params: Reply(200, refusal)  # type: ignore[method-assign]
    sink, queue = report_run(site, store)
    assert len(sink.lots) == 3
    assert all(lot.inspection is None for lot in sink.lots)
    assert queue.finished is not None
    result = queue.finished[1]
    assert (result["reports"], result["reports_failed"], result["reports_unavailable"]) == (0, 0, 3)

    site.details.clear()
    report_run(site, store)
    assert site.details == []  # the stored card says the link was refused: nothing fetched again
