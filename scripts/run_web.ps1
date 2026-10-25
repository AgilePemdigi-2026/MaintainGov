# Jalankan Next.js Frontend
$ROOT = Split-Path -Parent $PSScriptRoot
Set-Location $ROOT

Set-Location apps/web
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "  MaintainGov: Next.js Web (Port 3000)" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan
npm run dev -- -p 3000 -H 127.0.0.1
