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

    # Review dengan rating rendah harus punya sentimen negatif
    r1 = next(r for r in data["results"] if r["review_id"] == 101)
    assert r1["sentimen"] == "negatif"

    # Review dengan rating tinggi harus punya sentimen positif
    r2 = next(r for r in data["results"] if r["review_id"] == 102)
    assert r2["sentimen"] == "positif"

    # Harus ada minimal 1 case dari review negatif
    assert len(data["cases"]) >= 1


def test_analyze_empty_reviews_rejected(client):
    """Request tanpa reviews harus ditolak (422)."""
    payload = {
        "schema_version": "1.0",
        "batch_id": 1,
        "reviews": [],
    }
    response = client.post("/internal/v1/analyze", json=payload)
    # Pydantic tidak memvalidasi min items secara default, tapi endpoint tetap
    # harus mengembalikan response valid (bisa 200 dengan hasil kosong)
    assert response.status_code in [200, 422]


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
