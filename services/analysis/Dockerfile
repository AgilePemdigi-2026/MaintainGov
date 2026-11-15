FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    UC01_ARTIFACT_DIR=/app/artifacts

WORKDIR /app
COPY services/analysis/requirements.txt ./requirements.txt
RUN pip install --no-cache-dir -r requirements.txt
COPY services/analysis/ ./

RUN useradd --create-home --uid 10001 appuser && mkdir -p /app/artifacts \
    && chown -R appuser:appuser /app
USER appuser
EXPOSE 8001
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8001"]
