from __future__ import annotations

import json
from pathlib import Path
from types import SimpleNamespace
from typing import Any

import pytest

from collector import che168
from collector.adapters.che168 import api as module
from collector.adapters.che168.api import Che168Api, CollectionError, CurlTransport, Reply
from collector.adapters.che168.signing import Signer
from tests.test_che168 import Fake, detail, reply


class Transport:
    def __init__(self, session: str) -> None:
        self.fake = Fake()
        self.closed = False

    def request(self, url: str, params: dict[str, str]) -> Reply:
        return self.fake.request(url, params)

    def close(self) -> None:
        self.closed = True

    def rotate(self) -> None:
        pass


def fast_client(*args: Any, **kwargs: Any) -> Che168Api:
    kwargs["delay"] = 0
    return Che168Api(*args, **kwargs)


def test_cli_collect_resume_and_jsonl(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
    capsys: pytest.CaptureFixture[str],
) -> None:
    monkeypatch.setattr(che168, "CurlTransport", Transport)
    monkeypatch.setattr(che168, "Che168Api", fast_client)
    args = ["--output", str(tmp_path), "--max-pages", "2", "--max-cars", "1"]
    assert che168.main(args) == 0
    first = json.loads(capsys.readouterr().out.splitlines()[-1])
    assert first["status"] == "car_limit"
    assert first["pending_details"] == 1
    assert che168.main(["--output", str(tmp_path), "--resume", "--max-cars", "10"]) == 0
    final = json.loads((tmp_path / "summary.json").read_text())
    assert final["records_written"] == 3
    assert final["status"] == "api_end_reached"
    assert final["full_run"] is False
    assert che168.main(args) == 1  # never overwrite an existing run


def test_cli_error_exit_and_no_credentials_in_log(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
    capsys: pytest.CaptureFixture[str],
) -> None:
    monkeypatch.setattr(che168, "CurlTransport", Transport)
    monkeypatch.setattr(che168, "Che168Api", fast_client)
    assert che168.main(["--output", str(tmp_path), "--max-requests", "1"]) == 1
    assert json.loads(capsys.readouterr().out.splitlines()[-1])["status"] == "request_limit"
    assert (tmp_path / "cars.jsonl").read_text() == ""
    assert json.loads((tmp_path / "summary.json").read_text())["pending_details"] == 2


def test_cli_environment_and_optional_dependency_errors(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    args = ["--output", str(tmp_path / "out"), "--env-file", str(tmp_path / "config.env")]
    assert che168.main(args) == 1
    (tmp_path / "config.env").write_text("# synthetic env file\n")

    def missing_module(name: str) -> Any:
        raise ImportError(name)

    monkeypatch.setattr(che168, "import_module", missing_module)
    assert che168.main(args) == 2
    loaded = []
    monkeypatch.setattr(
        che168,
        "import_module",
        lambda name: SimpleNamespace(load_dotenv=lambda path, override: loaded.append((path, override))),
    )
    monkeypatch.setattr(che168, "CurlTransport", Transport)
    monkeypatch.setattr(che168, "Che168Api", fast_client)
    assert che168.main(args) == 0
    assert loaded == [(tmp_path / "config.env", False)]


@pytest.mark.parametrize("args", [["--delay", "0"], ["--delay", "nan"], ["--max-pages", "0"]])
def test_cli_bad_limits(tmp_path: Path, args: list[str]) -> None:
    with pytest.raises(SystemExit) as exc:
        che168.main(["--output", str(tmp_path), *args])
    assert exc.value.code == 2


def test_interruption_exports_checkpoint(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    class Interrupted(Transport):
        def request(self, url: str, params: dict[str, str]) -> Reply:
            raise KeyboardInterrupt

    monkeypatch.setattr(che168, "CurlTransport", Interrupted)
    assert che168.main(["--output", str(tmp_path)]) == 130
    assert json.loads((tmp_path / "summary.json").read_text())["next_page"] == 1


def test_curl_transport_safe_configuration_and_errors(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("FLOPPY_USERNAME", "test")
    monkeypatch.setenv("FLOPPY_PASSWORD", "private")
    monkeypatch.setenv("FLOPPY_HOST", "proxy.example:10080")
    calls: list[dict[str, Any]] = []

    class Session:
        def __init__(self, **kwargs: Any) -> None:
            self.proxies: dict[str, str] = {}
            self.headers: dict[str, str] = {}
            self.trust_env = True

        def get(self, url: str, **kwargs: Any) -> Any:
            calls.append(kwargs)
            if url == "error":
                raise RuntimeError("private")
            return SimpleNamespace(status_code=200, content=b"{}")

        def close(self) -> None:
            pass

    monkeypatch.setattr(module, "import_module", lambda name: SimpleNamespace(Session=Session))
    transport = CurlTransport("session")
    assert transport.request("test", {}).status == 200
    assert calls[-1]["allow_redirects"] is False
    assert transport.session.trust_env is False
    with pytest.raises(CollectionError, match=r"^network_error$"):
        transport.request("error", {})
    transport.close()


def test_temporary_http_retry_and_empty_catalog() -> None:
    responses = [Reply(503, b""), reply(detail())]
    api = Che168Api(lambda u, p: responses.pop(0), Signer.bundled(), delay=0)
    assert api.detail("1")["infoid"] == 1
    assert api.requests == 2
    result = {"pageindex": 1, "pagesize": 20, "carlist": [], "totalcount": 0, "pagecount": 0}
    api = Che168Api(lambda u, p: reply(result), Signer.bundled(), delay=0)
    page = api.search(1, 20)
    assert page.ids == ()
    assert page.total == 0
