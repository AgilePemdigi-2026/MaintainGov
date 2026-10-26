"""Contract coverage independent of private model artifacts."""
import json
from pathlib import Path
import subprocess
import sys

import pytest
from jsonschema import Draft202012Validator
from pydantic import ValidationError

from app.schemas import AnalyzeRequest, AnalyzeResponse

ROOT = Path(__file__).resolve().parents[3]


@pytest.mark.parametrize("kind", ["request", "response"])
def test_published_example_is_accepted_by_schema_and_runtime(kind):
    payload = json.loads((ROOT / f"contracts/examples/analysis-{kind}.example.json").read_text())
    schema = json.loads((ROOT / f"contracts/analysis-{kind}.schema.json").read_text())
    Draft202012Validator(schema).validate(payload)
    (AnalyzeRequest if kind == "request" else AnalyzeResponse).model_validate(payload)


def test_schema_export_has_no_drift():
    subprocess.run([sys.executable, str(ROOT / "scripts/export_analysis_contracts.py"), "--check"], check=True)


def test_null_severity_and_english_sentiment_are_valid_for_review_without_issue():
    payload = json.loads((ROOT / "contracts/examples/analysis-response.example.json").read_text())
    payload["results"][0].update(severity=None, sentimen="neutral", sentiment="neutral")
    schema = json.loads((ROOT / "contracts/analysis-response.schema.json").read_text())
    Draft202012Validator(schema).validate(payload)
    AnalyzeResponse.model_validate(payload)


def test_legacy_sentiment_and_critical_case_are_rejected():
    payload = json.loads((ROOT / "contracts/examples/analysis-response.example.json").read_text())
    payload["results"][0]["sentimen"] = "negatif"
    payload["cases"][0]["severity"] = "critical"
    schema = json.loads((ROOT / "contracts/analysis-response.schema.json").read_text())
    assert len(list(Draft202012Validator(schema).iter_errors(payload))) >= 2
    with pytest.raises(ValidationError):
        AnalyzeResponse.model_validate(payload)


def test_duplicate_review_ids_are_rejected_by_runtime():
    review = {"review_id": 1, "text": "Tidak bisa login", "rating": 1}
    with pytest.raises(ValidationError, match="duplikat"):
        AnalyzeRequest(batch_id=1, reviews=[review, review])
