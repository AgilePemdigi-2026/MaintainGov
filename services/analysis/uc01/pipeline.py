"""UC01 inference pipeline, ported from MaintainGov_UC01_run.ipynb cells 28, 71, 73.

Do not confuse draft category/severity/grouping rules with gold-label models.
"""
from __future__ import annotations

import os
import hashlib
import json
from functools import lru_cache
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
import sklearn
from sklearn.cluster import AgglomerativeClustering
from sklearn.feature_extraction.text import TfidfVectorizer

from .maintain_gov_preprocess import feature_frame, normalize_text
from .schemas import AnalysisResponse, AnalyzeRequest, CaseResult, ReviewResult

ARTIFACT_DIR = Path(os.environ.get("UC01_ARTIFACT_DIR", Path(__file__).resolve().parent.parent / "artifacts"))
SEVERITY_VALUE = {"low": 1, "medium": 2, "high": 3}
NEGATORS = {"tidak", "bukan", "belum", "jangan"}
BOUNDARIES = {".", ",", "!", "?", ";", ":", "tapi", "tetapi", "namun"}


@lru_cache(maxsize=1)
def load_resources():
    # joblib/pickle artifacts must come from a trusted, locally audited source only.
    # Preserve exact model training version; sklearn pickles are not cross-version stable.
    if sklearn.__version__ != "1.6.1":
        raise RuntimeError(
            f"Artefak Colab dilatih menggunakan scikit-learn 1.6.1, "
            f"tetapi runtime ini memakai {sklearn.__version__}. "
            "Install requirements.txt pada virtual environment Python 3.13 terlebih dahulu."
        )
    model = joblib.load(ARTIFACT_DIR / "sentiment_pipeline.joblib")
    slang = json.loads((ARTIFACT_DIR / "slang_map.json").read_text(encoding="utf-8"))
    rules = json.loads((ARTIFACT_DIR / "domain_rules.json").read_text(encoding="utf-8"))
    signature = hashlib.sha256(json.dumps(rules, sort_keys=True).encode()).hexdigest()[:12]
    return model, slang, rules, signature


def is_negated(tokens: list[str], index: int) -> bool:
    # Identik dengan notebook: satu atau tiga negasi dalam 3 token sebelumnya membalik makna.
    previous = []
    for token in reversed(tokens[max(0, index - 3):index]):
        if token in BOUNDARIES:
            break
        previous.append(token)
    return sum(token in NEGATORS for token in previous) % 2 == 1


def domain_matches(tokens: list[str], phrase: str) -> bool:
    words = phrase.split()
    return any(
        tokens[i:i + len(words)] == words and not is_negated(tokens, i)
        for i in range(len(tokens) - len(words) + 1)
    )


def domain_result(text: str, rules: dict) -> dict:
    tokens = text.split()
    matches = [
        rule for rule in rules["issues"]
        if any(domain_matches(tokens, phrase) for phrase in rule["phrases"])
    ]
    matches.sort(key=lambda r: (-SEVERITY_VALUE[r["severity"]], r["key"]))
    rule = matches[0] if matches else None
    return {
        "rule_key": rule["key"] if rule else None,
        "category": rule["category"] if rule else "other",
        "severity": rule["severity"] if rule else None,
        "issue": rule["title"] if rule else None,
        "module": rule["module"] if rule else None,
        "feature": rule["feature"] if rule else None,
        "matched_rule_keys": [item["key"] for item in matches],
        "needs_manual_review": not rule or len(matches) > 1 or rule["key"] == "feature_request",
    }


def group_issue_reviews(subset: pd.DataFrame, rule_key: str) -> list[int]:
    """Reproduce notebook's per-rule grouping, including exact-text fallback."""
    if rule_key == "feature_request":
        return list(range(len(subset)))
    if len(subset) == 1:
        return [0]
    if len(subset) > 600:
        return pd.factorize(subset["text_group"], sort=True)[0].tolist()
    vectorizer = TfidfVectorizer(ngram_range=(1, 2), min_df=1, max_features=6000)
    matrix = vectorizer.fit_transform(subset["clean_text"])
    if (matrix.getnnz(axis=1) == 0).any():
        return pd.factorize(subset["text_group"], sort=True)[0].tolist()
    return AgglomerativeClustering(
        n_clusters=None,
        distance_threshold=0.45,
        metric="cosine",
        linkage="average",
    ).fit_predict(matrix.toarray()).tolist()


def case_priority(severity: str, frequency: int) -> float:
    return round(100 * (0.7 * SEVERITY_VALUE[severity] / 3 + 0.3 * min(frequency / 10, 1)), 2)


def analyze(request: AnalyzeRequest) -> AnalysisResponse:
    model, slang, rules, signature = load_resources()
    source_texts = [review.text for review in request.reviews]
    features = feature_frame(source_texts, slang)
    predictions = list(model.predict(features))

    working_rows: list[dict] = []
    for i, (review, sentiment) in enumerate(zip(request.reviews, predictions)):
        clean_text = str(features.iloc[i]["clean_text"])
        match = domain_result(clean_text, rules)
        working_rows.append({
            **match,
            "review_id": review.review_id,
            "clean_text": clean_text,
            "text_group": hashlib.sha256(clean_text.encode()).hexdigest(),
            "sentiment": str(sentiment),
            "case_group": None,
        })

    df = pd.DataFrame(working_rows)
    candidates = df[df["rule_key"].notna()]
    for key, subset in candidates.groupby("rule_key", sort=True):
        cluster_ids = group_issue_reviews(subset, key)
        for pos, cluster_id in zip(subset.index, cluster_ids):
            df.at[pos, "case_group"] = f"{key}:cluster_{cluster_id:04d}"

    results: list[ReviewResult] = []
    for row in df.to_dict(orient="records"):
        results.append(ReviewResult(
            review_id=row["review_id"],
            category=row["category"],
            sentiment=row["sentiment"],
            severity=row["severity"],
            issue=row["issue"],
            module=row["module"],
            feature=row["feature"],
            group_key=row["case_group"],
            needs_manual_review=row["needs_manual_review"],
            matched_rule_keys=row["matched_rule_keys"],
            disposition="candidate_issue" if row["rule_key"] else "needs_manual_review",
        ))

    lookup = {rule["key"]: rule for rule in rules["issues"]}
    cases: list[CaseResult] = []
    for group_key, subset in df[df["case_group"].notna()].groupby("case_group", sort=True):
        rule = lookup[subset["rule_key"].iloc[0]]
        cases.append(CaseResult(
            group_key=group_key,
            member_review_ids=list(subset["review_id"]),
            title=rule["title"],
            summary=f"{len(subset)} ulasan cocok dengan aturan {rule['key']}; perlu review konteks.",
            category=rule["category"],
            severity=rule["severity"],
            module=rule["module"],
            feature=rule["feature"],
            frequency=int(len(subset)),
            priority_score=case_priority(rule["severity"], int(len(subset))),
            recommendation=rule["recommendation"],
        ))
    cases.sort(key=lambda case: (-case.priority_score, case.group_key))

    member_ids = [str(id) for case in cases for id in case.member_review_ids]
    assert len(member_ids) == len(set(member_ids)), "Satu ulasan masuk lebih dari satu case"
    assert len(member_ids) == int(len(candidates)), "Ada candidate issue belum masuk case"

    return AnalysisResponse(
        analysis_version=f"colab-1.1-{signature}",
        batch_id=request.batch_id,
        rules_status=rules["status"],
        results=results,
        cases=cases,
        warnings=[
            "Prediksi sentimen menggunakan proksi rating; bukan gold sentiment manusia.",
            "Aturan category, severity, issue grouping, dan priority masih draf dan memerlukan validasi.",
            "sentiment_lexicon=null karena lexicon lengkap tidak termasuk artefak privat yang diekspor.",
            "Maintenance Case belum dipersistenkan; backend Laravel harus menyimpan hasilnya.",
        ],
        summary={
            "total_reviews": len(results),
            "candidate_issue_reviews": int(len(candidates)),
            "manual_review_reviews": int(sum(row.disposition == "needs_manual_review" for row in results)),
            "candidate_cases": len(cases),
        },
    )
