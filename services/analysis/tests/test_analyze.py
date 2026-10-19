"""
Test sederhana untuk memverifikasi FastAPI analysis service berjalan.
Jalankan: cd services/analysis && .venv/Scripts/python -m pytest tests/ -v
"""

import pytest
from fastapi.testclient import TestClient

from main import app


@pytest.fixture
def client():
    return TestClient(app)


def test_health_check(client):
    """Health endpoint harus mengembalikan status ok."""
    response = client.get("/internal/health")
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "ok"
    assert data["service"] == "analysis"


def test_analyze_valid_request(client):
    """Endpoint analyze harus menerima request valid dan mengembalikan hasil."""
    payload = {
        "schema_version": "1.0",
        "batch_id": 1,
        "reviews": [
            {"review_id": 101, "text": "Tidak bisa login setelah update", "rating": 1},
            {"review_id": 102, "text": "Aplikasi bagus sekali", "rating": 5},
        ],
    }
    response = client.post("/internal/v1/analyze", json=payload)
    assert response.status_code == 200
    data = response.json()

    assert data["batch_id"] == 1
    assert data["schema_version"] == "1.0"
    assert len(data["results"]) == 2

    # Prediksi berasal dari teks; rating hanya metadata sumber.
    r1 = next(r for r in data["results"] if r["review_id"] == 101)
    assert r1["sentimen"] == "negative"

    r2 = next(r for r in data["results"] if r["review_id"] == 102)
    assert r2["sentimen"] == "positive"

    # Aturan issue login membentuk kandidat case.
    assert len(data["cases"]) >= 1


def test_analyze_empty_reviews_rejected(client):
    """Request tanpa reviews harus ditolak (422)."""
    payload = {
        "schema_version": "1.0",
        "batch_id": 1,
        "reviews": [],
    }
    response = client.post("/internal/v1/analyze", json=payload)
    assert response.status_code == 422


def test_analyze_invalid_rating_rejected(client):
    """Rating di luar range 1-5 harus ditolak."""
    payload = {
        "schema_version": "1.0",
        "batch_id": 1,
        "reviews": [
            {"review_id": 1, "text": "Test", "rating": 0},
        ],
    }
    response = client.post("/internal/v1/analyze", json=payload)
    assert response.status_code == 422


def test_sentiment_is_from_text_not_input_rating(client):
    def run(rating):
        response = client.post("/internal/v1/analyze", json={"batch_id": 10, "reviews": [
            {"review_id": 1, "text": "Tidak bisa login sejak pembaruan aplikasi", "rating": rating}]})
        assert response.status_code == 200
        return response.json()
    low, high = run(1), run(5)
    assert low == high
    assert low["results"][0]["issue"] != "Placeholder issue"
    assert low["rules_status"] == "draft_pending_team_review"
    assert all(0 <= c["priority_score"] <= 10 for c in low["cases"])


def test_duplicate_review_ids_rejected(client):
    r = {"review_id": 1, "text": "tidak bisa login", "rating": 1}
    assert client.post("/internal/v1/analyze", json={"batch_id": 1, "reviews": [r, r]}).status_code == 422


def test_blank_text_rejected(client):
    assert client.post("/internal/v1/analyze", json={"batch_id": 1, "reviews": [
        {"review_id": 1, "text": "   ", "rating": 1}]}).status_code == 422
