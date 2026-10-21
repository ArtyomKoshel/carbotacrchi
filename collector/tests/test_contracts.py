"""The committed JSON Schema is current, and it agrees with pydantic on every shared fixture."""

from __future__ import annotations

import json
from pathlib import Path

import pytest
from jsonschema import Draft202012Validator, FormatChecker
from pydantic import ValidationError

from collector import contracts
from collector.domain.carlot import CarLotV1
from tests.conftest import FIXTURES, load

VALID = sorted((FIXTURES / "valid").glob("*.json"))
INVALID = sorted((FIXTURES / "invalid").glob("*.json"))


@pytest.fixture(scope="module")
def validator() -> Draft202012Validator:
    schema = json.loads(contracts.CARLOT_SCHEMA_PATH.read_text(encoding="utf-8"))
    Draft202012Validator.check_schema(schema)
    return Draft202012Validator(schema, format_checker=FormatChecker())


def test_committed_schema_is_current() -> None:
    assert contracts.main(["check"]) == 0, "run: python -m collector.contracts write"


def test_fixture_sets_are_not_empty() -> None:
    assert VALID
    assert INVALID


@pytest.mark.parametrize("path", VALID, ids=lambda p: p.stem)
def test_valid_fixture_passes_both_validators(path: Path, validator: Draft202012Validator) -> None:
    data = load(path)
    assert not list(validator.iter_errors(data))
    lot = CarLotV1.model_validate(data)
    # What the collector actually sends (serialized form) is valid too.
    assert not list(validator.iter_errors(json.loads(lot.model_dump_json())))


@pytest.mark.parametrize("path", INVALID, ids=lambda p: p.stem)
def test_invalid_fixture_fails_both_validators(path: Path, validator: Draft202012Validator) -> None:
    data = load(path)
    assert list(validator.iter_errors(data)), "JSON Schema accepted an invalid fixture"
    with pytest.raises(ValidationError):
        CarLotV1.model_validate(data)


def test_cli_rejects_unknown_command() -> None:
    assert contracts.main(["nope"]) == 2


def test_cli_check_fails_on_stale_schema(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    stale = tmp_path / "carlot.v1.schema.json"
    stale.write_text("{}", encoding="utf-8")
    monkeypatch.setattr(contracts, "CARLOT_SCHEMA_PATH", stale)
    assert contracts.main(["check"]) == 1
    assert contracts.main(["write"]) == 0
    assert contracts.main(["check"]) == 0
