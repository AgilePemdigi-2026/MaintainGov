from contextlib import asynccontextmanager
from fastapi import FastAPI
from app.routers import analyze
from uc01.pipeline import load_resources

@asynccontextmanager
async def lifespan(app: FastAPI):
    load_resources()
    yield

app = FastAPI(title="MaintainGov Analysis Service", version="0.2.0", lifespan=lifespan,
              docs_url="/internal/docs", openapi_url="/internal/openapi.json")
app.include_router(analyze.router)

@app.get("/internal/health")
def health_check():
    _, _, rules, signature = load_resources()
    return {"status": "ok", "service": "analysis", "model": "loaded",
            "rules_status": rules["status"], "rule_sha12": signature}
