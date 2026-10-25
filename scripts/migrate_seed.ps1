# Migrasi dan Seed Database MaintainGov
$ROOT = Split-Path -Parent $PSScriptRoot
Set-Location $ROOT

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    $env:PATH = "C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64;C:\laragon\bin\composer;" + $env:PATH
}

Set-Location apps/api
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "  MaintainGov: Migration & Database Seeder" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan

Write-Host "Menjalankan migrasi database..." -ForegroundColor Yellow
php artisan migrate --force

Write-Host "Menjalankan database seeder..." -ForegroundColor Yellow
php artisan db:seed --force

Write-Host "Selesai! Database siap digunakan." -ForegroundColor Green
