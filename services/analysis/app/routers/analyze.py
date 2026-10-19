from fastapi import APIRouter
from app.schemas import AnalyzeRequest, AnalyzeResponse
from uc01.pipeline import analyze as run_inference
from uc01.schemas import AnalyzeRequest as ModelRequest

router = APIRouter(prefix="/internal/v1", tags=["analysis"])

@router.post("/analyze", response_model=AnalyzeResponse)
def analyze(payload: AnalyzeRequest):
    output = run_inference(ModelRequest(batch_id=payload.batch_id,
        reviews=[{"review_id": r.review_id, "text": r.text} for r in payload.reviews])).model_dump()
    return {
        "schema_version": "1.0", "analysis_version": output["analysis_version"],
        "batch_id": payload.batch_id, "rules_status": output["rules_status"],
        "sentiment_target_mode": output["sentiment_target_mode"], "warnings": output["warnings"],
        "results": [{**r, "kategori": r["category"], "sentimen": r["sentiment"],
            "modul": r["module"], "fitur": r["feature"]} for r in output["results"]],
        "cases": [{**c, "judul": c["title"], "ringkasan": c["summary"],
            "priority_score": round(c["priority_score"] / 10, 2)} for c in output["cases"]],
    }
