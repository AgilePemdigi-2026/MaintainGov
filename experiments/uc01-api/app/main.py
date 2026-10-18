from __future__ import annotations

import csv
import io
import re
from contextlib import asynccontextmanager
from datetime import datetime

from fastapi import FastAPI, File, HTTPException, UploadFile
from fastapi.responses import JSONResponse
from pydantic import ValidationError

from .pipeline import analyze, load_resources
from .schemas import AnalysisResponse, AnalyzeRequest, ReviewInput

MAX_CSV_BYTES = 8 * 1024 * 1024
CSV_REQUIRED = {"reviewId", "Review Teks", "rating", "date"}


@asynccontextmanager
async def lifespan(app: FastAPI):
    # Fail fast if the trained model cannot be loaded by the installed sklearn version.
    load_resources()
    yield


app = FastAPI(title="MaintainGov UC01 NLP Service", version="0.1.0", lifespan=lifespan)


@app.get("/health")
def health():
    _, _, rules, sig = load_resources()
    return {"status": "ok", "model": "loaded", "rules_status": rules["status"], "rule_sha12": sig}


@app.post("/v1/analyze", response_model=AnalysisResponse)
def analyze_json(payload: AnalyzeRequest):
    try:
        return analyze(payload)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc


def parse_csv(raw: bytes) -> tuple[list[ReviewInput], dict[str, int]]:
    if not raw:
        raise ValueError("CSV kosong")
    if len(raw) > MAX_CSV_BYTES:
        raise ValueError("CSV melebihi batas 8 MiB")
    try:
        decoded = raw.decode("utf-8-sig")
    except UnicodeDecodeError as exc:
        raise ValueError("CSV harus menggunakan encoding UTF-8") from exc

    try:
        reader = csv.reader(io.StringIO(decoded, newline=""), delimiter=",", strict=True)
        header = next(reader)
        if len(header) != len(set(header)):
            raise ValueError("Nama kolom ganda")
        missing = CSV_REQUIRED - set(header)
        if missing:
            raise ValueError(f"Kolom wajib belum lengkap: {sorted(missing)}")
        indexes = {name: header.index(name) for name in CSV_REQUIRED}
        rows: list[ReviewInput] = []
        rejected = 0
        seen_ids: set[str] = set()
        for record_number, values in enumerate(reader, start=2):
            if len(values) != len(header):
                rejected += 1
                continue
            rid = values[indexes["reviewId"]].strip()
            text = values[indexes["Review Teks"]].strip()
            rating = values[indexes["rating"]].strip()
            date = values[indexes["date"]].strip()
            if not rid or not text or rid in seen_ids or rating not in {"1", "2", "3", "4", "5"}:
                rejected += 1
                continue
            try:
                datetime.strptime(date, "%Y-%m-%d %H:%M:%S")
                review = ReviewInput(review_id=rid, text=text)
            except (ValueError, ValidationError):
                rejected += 1
                continue
            seen_ids.add(rid)
            rows.append(review)
            if len(rows) > 10000:
                raise ValueError("Maksimum 10.000 ulasan per request")
    except (csv.Error, StopIteration) as exc:
        raise ValueError("CSV tidak valid atau tidak memiliki header") from exc
    if not rows:
        raise ValueError("Tidak ada ulasan valid untuk dianalisis")
    return rows, {"accepted": len(rows), "quarantined": rejected}


@app.post("/v1/analyze-csv")
async def analyze_csv(batch_id: str, file: UploadFile = File(...)):
    if not file.filename or not file.filename.lower().endswith(".csv"):
        raise HTTPException(status_code=400, detail="Unggah file dengan ekstensi .csv")
    data = await file.read(MAX_CSV_BYTES + 1)
    try:
        reviews, csv_audit = parse_csv(data)
        result = analyze(AnalyzeRequest(batch_id=batch_id, reviews=reviews)).model_dump()
        result["csv_audit"] = csv_audit
        return result
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
