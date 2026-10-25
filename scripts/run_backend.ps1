# Jalankan Laravel Backend API
$ROOT = Split-Path -Parent $PSScriptRoot
Set-Location $ROOT

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    $env:PATH = "C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64;C:\laragon\bin\composer;" + $env:PATH
}

Set-Location apps/api
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "  MaintainGov: Laravel API (Port 8000)" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan
php artisan serve --host=127.0.0.1 --port=8000
