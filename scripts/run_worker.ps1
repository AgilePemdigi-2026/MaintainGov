# Jalankan Laravel Queue Worker
$ROOT = Split-Path -Parent $PSScriptRoot
Set-Location $ROOT

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    $env:PATH = "C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64;C:\laragon\bin\composer;" + $env:PATH
}

Set-Location apps/api
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "  MaintainGov: Queue Worker" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host " Status: Worker AKTIF dan standby mendengarkan antrean (queue)." -ForegroundColor Green
Write-Host " Catatan: Terminal ini memang sengaja MENUNGGU (tidak keluar)." -ForegroundColor Yellow
Write-Host " Saat ada ulasan CSV diunggah di web, worker akan memprosesnya di sini." -ForegroundColor Yellow
Write-Host " BIARKAN terminal ini tetap terbuka (jangan ditutup / Ctrl+C)." -ForegroundColor Yellow
Write-Host "==========================================" -ForegroundColor Cyan
php artisan queue:work --sleep=1 --tries=1 --timeout=180 --verbose
