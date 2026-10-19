from typing import Literal
from pydantic import BaseModel, Field, model_validator
from uc01.schemas import ReviewResult as InferenceReview, CaseResult as InferenceCase

class ReviewInput(BaseModel):
    review_id: int = Field(gt=0)
    text: str = Field(min_length=1, max_length=10000, pattern=r"\S")
    rating: int = Field(ge=1, le=5)

class AnalyzeRequest(BaseModel):
    schema_version: Literal["1.0"] = "1.0"
    batch_id: int = Field(gt=0)
    reviews: list[ReviewInput] = Field(min_length=1, max_length=10000)

    @model_validator(mode="after")
    def unique_reviews(self):
        ids = [r.review_id for r in self.reviews]
        if len(ids) != len(set(ids)):
            raise ValueError("review_id duplikat")
        return self

class ReviewResult(InferenceReview):
    kategori: str
    sentimen: Literal["positive", "negative", "neutral"]
    modul: str | None = None
    fitur: str | None = None

class CaseResult(InferenceCase):
    judul: str
    ringkasan: str
    priority_score: float = Field(ge=0, le=10)

class AnalyzeResponse(BaseModel):
    schema_version: Literal["1.0"] = "1.0"
    analysis_version: str
    batch_id: int
    rules_status: str
    sentiment_target_mode: Literal["rating_proxy"]
    warnings: list[str]
    results: list[ReviewResult]
    cases: list[CaseResult]
