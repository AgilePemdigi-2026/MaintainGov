from typing import Literal
from pydantic import BaseModel, Field, field_validator, model_validator


class ReviewInput(BaseModel):
    review_id: str | int = Field(description="Identitas review dari sumber/DB")
    text: str = Field(min_length=1, max_length=10000)

    @field_validator("review_id")
    @classmethod
    def validate_id(cls, value: str | int):
        if isinstance(value, str) and not value.strip():
            raise ValueError("review_id kosong")
        return value

    @field_validator("text")
    @classmethod
    def nonblank_text(cls, text: str):
        if not text.strip():
            raise ValueError("Teks ulasan kosong")
        return text


class AnalyzeRequest(BaseModel):
    batch_id: str | int = Field(description="ID batch ditentukan backend Laravel")
    reviews: list[ReviewInput] = Field(min_length=1, max_length=10000)

    @model_validator(mode="after")
    def reject_duplicate_ids(self):
        ids = [str(r.review_id) for r in self.reviews]
        if len(ids) != len(set(ids)):
            raise ValueError("review_id duplikat dalam batch")
        return self


class ReviewResult(BaseModel):
    review_id: str | int
    category: str
    sentiment: str
    sentiment_target_mode: Literal["rating_proxy"] = "rating_proxy"
    sentiment_lexicon: None = None
    severity: str | None = None
    issue: str | None = None
    module: str | None = None
    feature: str | None = None
    group_key: str | None = None
    needs_manual_review: bool
    matched_rule_keys: list[str]
    disposition: str


class CaseResult(BaseModel):
    group_key: str
    member_review_ids: list[str | int]
    title: str
    summary: str
    category: str
    severity: str
    module: str | None = None
    feature: str | None = None
    frequency: int
    priority_score: float
    recommendation: str
    recommendation_status: str = "suggestion_requires_validation"
    proposed_status: str = "Pending"
    persisted: bool = False


class AnalysisResponse(BaseModel):
    schema_version: str = "1.0"
    contract_status: str = "research_draft_requires_review"
    analysis_version: str
    batch_id: str | int
    rules_status: str
    sentiment_target_mode: str = "rating_proxy"
    results: list[ReviewResult]
    cases: list[CaseResult]
    warnings: list[str]
    summary: dict[str, int]
