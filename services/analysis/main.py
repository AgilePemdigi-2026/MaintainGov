"""
MaintainGov Analysis Service
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
Layanan analisis feedback pengguna menggunakan FastAPI.
Menerima ulasan dari Laravel worker dan mengembalikan hasil analisis
(kategori, sentimen, severity, issue, grouping, prioritas, rekomendasi).
"""

from fastapi import FastAPI
from app.routers import analyze

app = FastAPI(
    title="MaintainGov Analysis Service",
    description="Internal analysis service – called by Laravel worker, not by browser.",
    version="0.1.0",
    docs_url="/internal/docs",
    openapi_url="/internal/openapi.json",
)

app.include_router(analyze.router)


@app.get("/internal/health")
async def health_check():
    """Endpoint sederhana untuk memastikan service berjalan."""
    return {"status": "ok", "service": "analysis"}
