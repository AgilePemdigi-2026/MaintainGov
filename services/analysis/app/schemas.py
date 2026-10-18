"""
Schemas Pydantic untuk kontrak internal Laravel → FastAPI.
Sesuai dengan kontrak di docs/ARCHITECTURE.md §4.
"""

from __future__ import annotations

from pydantic import BaseModel, Field


# ── Request ──────────────────────────────────────────────────────────

class ReviewInput(BaseModel):
    review_id: int
    text: str
    rating: int = Field(ge=1, le=5)


class AnalyzeRequest(BaseModel):
    schema_version: str = "1.0"
    batch_id: int
    reviews: list[ReviewInput]


# ── Response ─────────────────────────────────────────────────────────

class ReviewResult(BaseModel):
    review_id: int
    kategori: str
    sentimen: str
    severity: str
    issue: str | None = None
    modul: str | None = None
    fitur: str | None = None


class CaseResult(BaseModel):
    group_key: str
    member_review_ids: list[int]
    judul: str
    ringkasan: str
    recommendation: str | None = None
    priority_score: float = Field(ge=0, le=10)


class AnalyzeResponse(BaseModel):
    schema_version: str = "1.0"
    analysis_version: str = "baseline-v0.1"
    batch_id: int
    results: list[ReviewResult]
    cases: list[CaseResult]
