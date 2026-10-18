<#
.SYNOPSIS
    Script setup otomatis MaintainGov untuk Windows (PowerShell).
.DESCRIPTION
    Memeriksa prasyarat, menginstall dependencies, dan menyiapkan environment.
    Jalankan dari root directory project (MaintainGov/).
.NOTES
    Tugas W10-02 — Hanif
#>

$ErrorActionPreference = "Stop"
$ROOT = $PSScriptRoot | Split-Path -Parent

Write-Host ""
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host "  MaintainGov Environment Setup" -ForegroundColor Cyan
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host ""

# ── 1. Cek Prasyarat ────────────────────────────────────────────────

Write-Host "[1/6] Memeriksa prasyarat..." -ForegroundColor Yellow

$checks = @(
    @{ Name = "PHP";      Cmd = "php --version";      Required = $true  },
    @{ Name = "Composer"; Cmd = "composer --version";  Required = $true  },
    @{ Name = "Node.js";  Cmd = "node --version";      Required = $true  },
    @{ Name = "npm";      Cmd = "npm --version";       Required = $true  },
    @{ Name = "Python";   Cmd = "python --version";    Required = $true  },
    @{ Name = "pip";      Cmd = "pip --version";       Required = $true  },
    @{ Name = "MySQL";    Cmd = "mysql --version";     Required = $true  }
)

$allPassed = $true
foreach ($check in $checks) {
    try {
        $result = Invoke-Expression $check.Cmd 2>&1 | Select-Object -First 1
        Write-Host "  ✅ $($check.Name): $result" -ForegroundColor Green
    }
    catch {
        if ($check.Required) {
            Write-Host "  ❌ $($check.Name): TIDAK DITEMUKAN" -ForegroundColor Red
            $allPassed = $false
        } else {
            Write-Host "  ⚠️  $($check.Name): tidak ditemukan (opsional)" -ForegroundColor DarkYellow
        }
    }
}

if (-not $allPassed) {
    Write-Host ""
    Write-Host "⚠️  Beberapa prasyarat belum terinstall." -ForegroundColor Red
    Write-Host "Lihat docs/SETUP_GUIDE.md untuk petunjuk instalasi." -ForegroundColor Red
    Write-Host "Tip: Gunakan Laragon (https://laragon.org) untuk PHP + Composer + MySQL." -ForegroundColor Yellow
    Write-Host ""
    $continue = Read-Host "Lanjutkan setup untuk komponen yang tersedia? (y/n)"
    if ($continue -ne "y") { exit 1 }
}

# ── 2. Setup Laravel Backend ────────────────────────────────────────

Write-Host ""
Write-Host "[2/6] Setup Laravel Backend (apps/api)..." -ForegroundColor Yellow

$apiDir = Join-Path $ROOT "apps\api"
if (Test-Path $apiDir) {
    Push-Location $apiDir

    if (-not (Test-Path ".env")) {
        Copy-Item ".env.example" ".env"
        Write-Host "  📄 .env dibuat dari .env.example" -ForegroundColor Green
    } else {
        Write-Host "  📄 .env sudah ada, dilewati" -ForegroundColor DarkGray
    }

    try {
        Write-Host "  📦 Menginstall Composer dependencies..."
        composer install --no-interaction --prefer-dist 2>&1 | Out-Null
        Write-Host "  ✅ Composer dependencies terinstall" -ForegroundColor Green

        Write-Host "  🔑 Generating app key..."
        php artisan key:generate --no-interaction 2>&1 | Out-Null
        Write-Host "  ✅ App key di-generate" -ForegroundColor Green
    }
    catch {
        Write-Host "  ⚠️  PHP/Composer belum tersedia, langkah ini dilewati" -ForegroundColor DarkYellow
    }

    Pop-Location
} else {
    Write-Host "  ⚠️  Folder apps/api tidak ditemukan" -ForegroundColor Red
}

# ── 3. Setup Next.js Frontend ───────────────────────────────────────

Write-Host ""
Write-Host "[3/6] Setup Next.js Frontend (apps/web)..." -ForegroundColor Yellow

$webDir = Join-Path $ROOT "apps\web"
if (Test-Path $webDir) {
    Push-Location $webDir

    if (-not (Test-Path ".env.local")) {
        Copy-Item ".env.example" ".env.local"
        Write-Host "  📄 .env.local dibuat dari .env.example" -ForegroundColor Green
    } else {
        Write-Host "  📄 .env.local sudah ada, dilewati" -ForegroundColor DarkGray
    }

    try {
        Write-Host "  📦 Menginstall npm dependencies..."
        npm install 2>&1 | Out-Null
        Write-Host "  ✅ npm dependencies terinstall" -ForegroundColor Green
    }
    catch {
        Write-Host "  ⚠️  npm gagal, periksa instalasi Node.js" -ForegroundColor Red
    }

    Pop-Location
} else {
    Write-Host "  ⚠️  Folder apps/web tidak ditemukan" -ForegroundColor Red
}

# ── 4. Setup FastAPI Analysis Service ───────────────────────────────

Write-Host ""
Write-Host "[4/6] Setup FastAPI Analysis Service (services/analysis)..." -ForegroundColor Yellow

$analysisDir = Join-Path $ROOT "services\analysis"
if (Test-Path $analysisDir) {
    Push-Location $analysisDir

    try {
        if (-not (Test-Path ".venv")) {
            Write-Host "  🐍 Membuat virtual environment..."
            python -m venv .venv 2>&1 | Out-Null
        }

        Write-Host "  📦 Menginstall Python dependencies..."
        & ".\.venv\Scripts\pip.exe" install -r requirements.txt 2>&1 | Out-Null
        Write-Host "  ✅ Python dependencies terinstall" -ForegroundColor Green
    }
    catch {
        Write-Host "  ⚠️  Python setup gagal, periksa instalasi Python" -ForegroundColor Red
    }

    Pop-Location
} else {
    Write-Host "  ⚠️  Folder services/analysis tidak ditemukan" -ForegroundColor Red
}

# ── 5. Setup Database ───────────────────────────────────────────────

Write-Host ""
Write-Host "[5/6] Memeriksa database..." -ForegroundColor Yellow

try {
    $dbCheck = mysql -u root -e "SELECT 1" 2>&1
    Write-Host "  ✅ MySQL dapat diakses" -ForegroundColor Green

    # Cek apakah database sudah ada
    $dbExists = mysql -u root -e "SHOW DATABASES LIKE 'maintaingov'" 2>&1
    if ($dbExists -match "maintaingov") {
        Write-Host "  📄 Database 'maintaingov' sudah ada" -ForegroundColor DarkGray
    } else {
        Write-Host "  📄 Membuat database 'maintaingov'..."
        mysql -u root -e "CREATE DATABASE maintaingov CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>&1 | Out-Null
        Write-Host "  ✅ Database 'maintaingov' dibuat" -ForegroundColor Green
    }

    # Jalankan migration
    Push-Location (Join-Path $ROOT "apps\api")
    try {
        Write-Host "  🔄 Menjalankan migration..."
        php artisan migrate --force --no-interaction 2>&1 | Out-Null
        Write-Host "  ✅ Migration berhasil" -ForegroundColor Green
    }
    catch {
        Write-Host "  ⚠️  Migration gagal (PHP belum tersedia?)" -ForegroundColor DarkYellow
    }
    Pop-Location
}
catch {
    Write-Host "  ⚠️  MySQL belum tersedia, buat database secara manual" -ForegroundColor DarkYellow
    Write-Host "  Lihat docs/SETUP_GUIDE.md bagian 'Setup Database'" -ForegroundColor DarkYellow
}

# ── 6. Ringkasan ────────────────────────────────────────────────────

Write-Host ""
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host "  Setup Selesai!" -ForegroundColor Cyan
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Jalankan masing-masing komponen di terminal terpisah:" -ForegroundColor White
Write-Host ""
Write-Host "  Terminal 1 (Backend):"  -ForegroundColor Yellow
Write-Host "    cd apps/api && php artisan serve" -ForegroundColor White
Write-Host ""
Write-Host "  Terminal 2 (Frontend):" -ForegroundColor Yellow
Write-Host "    cd apps/web && npm run dev" -ForegroundColor White
Write-Host ""
Write-Host "  Terminal 3 (Analysis):" -ForegroundColor Yellow
Write-Host "    cd services/analysis" -ForegroundColor White
Write-Host "    .\.venv\Scripts\Activate.ps1" -ForegroundColor White
Write-Host "    uvicorn main:app --port 8001 --reload" -ForegroundColor White
Write-Host ""
Write-Host "  URL:" -ForegroundColor Yellow
Write-Host "    Laravel API : http://localhost:8000" -ForegroundColor White
Write-Host "    Next.js Web : http://localhost:3000" -ForegroundColor White
Write-Host "    FastAPI     : http://localhost:8001" -ForegroundColor White
Write-Host ""
