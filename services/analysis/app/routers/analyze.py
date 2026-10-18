"""
Router /internal/v1/analyze
~~~~~~~~~~~~~~~~~~~~~~~~~~~~
Endpoint utama yang dipanggil oleh Laravel worker.

Implementasi saat ini adalah **stub** yang mengembalikan hasil placeholder
agar alur integrasi Laravel → FastAPI dapat diuji sebelum metode analisis
sesungguhnya (W10-07, oleh Gerard) selesai.
"""

from __future__ import annotations

import hashlib

from fastapi import APIRouter

from app.schemas import (
    AnalyzeRequest,
    AnalyzeResponse,
    CaseResult,
    ReviewResult,
)

router = APIRouter(prefix="/internal/v1", tags=["analysis"])


def _stub_analyze_review(review_id: int, text: str, rating: int) -> ReviewResult:
    """Analisis placeholder — akan diganti implementasi Gerard di W10-07."""
    # Sentimen sederhana berdasarkan rating
    if rating <= 2:
        sentimen = "negatif"
    elif rating == 3:
        sentimen = "netral"
    else:
        sentimen = "positif"

    return ReviewResult(
        review_id=review_id,
        kategori="other",
        sentimen=sentimen,
        severity="low",
        issue="Placeholder issue" if rating <= 2 else None,
        modul="Unknown",
        fitur="Unknown",
    )


def _stub_group_cases(results: list[ReviewResult], batch_id: int) -> list[CaseResult]:
    """Grouping placeholder — satu case per review yang memiliki issue."""
    cases: list[CaseResult] = []
    actionable = [r for r in results if r.issue]
    for r in actionable:
        gk = hashlib.md5(f"{batch_id}-{r.review_id}".encode()).hexdigest()[:8]
        cases.append(
            CaseResult(
                group_key=gk,
                member_review_ids=[r.review_id],
                judul=f"Issue dari ulasan #{r.review_id}",
                ringkasan=r.issue or "",
                recommendation="Perlu investigasi lebih lanjut.",
                priority_score=5.0,
            )
        )
    return cases


@router.post("/analyze", response_model=AnalyzeResponse)
async def analyze(payload: AnalyzeRequest) -> AnalyzeResponse:
    """
    Menerima daftar ulasan dari Laravel worker, mengembalikan
    hasil analisis per ulasan dan daftar maintenance case.

    **Stub** — Gerard akan mengganti logika ini pada W10-07.
    """
    results = [
        _stub_analyze_review(r.review_id, r.text, r.rating)
        for r in payload.reviews
    ]
    cases = _stub_group_cases(results, payload.batch_id)

    return AnalyzeResponse(
        batch_id=payload.batch_id,
        results=results,
        cases=cases,
    )
