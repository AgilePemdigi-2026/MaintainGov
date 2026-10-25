# Jalankan FastAPI Analysis Service
$ROOT = Split-Path -Parent $PSScriptRoot
Set-Location $ROOT

Set-Location services/analysis
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "  MaintainGov: FastAPI Service (Port 8001)" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan
$env:UC01_ARTIFACT_DIR = (Resolve-Path .\artifacts).Path
.\.venv\Scripts\python.exe -m uvicorn main:app --host 127.0.0.1 --port 8001 --reload
