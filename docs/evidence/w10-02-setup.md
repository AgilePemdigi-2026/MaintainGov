# Bukti Penyelesaian W10-02 — Setup Environment MaintainGov

**Penanggung jawab:** Muhammad Hanif (187241062) | **Reviewer:** Demas | **Tanggal:** 2026-10-18  
**Branch:** `feat/w10-02-setup-environment` | **Status:** ✅ Selesai (Semua komponen diverifikasi)

---

## 1. Ringkasan Runtime & Dependensi

| Komponen | Framework / Runtime | Versi | Jalur Eksekusi |
| --- | --- | --- | --- |
| **Frontend** | Node.js / Next.js / React | Node 22.22.1, Next 15.5.28, React 19.x | `apps/web` |
| **Backend** | PHP / Laravel / Composer | PHP 8.3.26, Laravel 13.37.0, Composer 2.8.4 | `apps/api` |
| **Analysis Service** | Python / FastAPI / Pydantic | Python 3.11.9, FastAPI 0.115.14, Pydantic 2.14.1 | `services/analysis` |
| **Database** | MySQL (Laragon) | MySQL 8.4.3 | `127.0.0.1:3306`, DB: `maintaingov` |

---

## 2. Artefak & File yang Ditambahkan/Disesuaikan

- **Panduan Setup:** `docs/SETUP_GUIDE.md` (Langkah instalasi lengkap, prasyarat, pemecahan masalah)
- **Skrip Otomasi:** `scripts/setup.ps1` (Pemeriksaan prasyarat & instalasi otomatis PowerShell)
- **Skrip Verifikasi DB:** `scripts/verify_db.php` (Pengecekan koneksi & tabel database)
- **Layanan Analisis FastAPI:** `services/analysis/` (struktur aplikasi FastAPI, endpoint stub, router, pydantic schemas)
- **Kontrak Antarmuka:** `contracts/` (JSON Schema request/response & contoh payload)
- **Dataset Fixture:** `data/fixtures/sample_10_reviews.csv` (10 sampel ulasan terkurasi)
- **Skenario BDD:** `tests/bdd/analyze_feedback.feature`, `tests/bdd/authentication.feature`
- **Contoh Lingkungan:** `apps/api/.env.example` (MySQL connection & `ANALYSIS_SERVICE_URL`) dan `services/analysis/.env.example`
- **Konfigurasi PHP Laragon:** Pengaktifan `extension=zip` pada `php.ini` untuk keandalan Composer

---

## 3. Hasil Pengujian & Verifikasi Tiap Komponen

### A. Next.js (`apps/web`)
```bash
npm run build
```
**Hasil:**
```text
▲ Next.js 15.5.28
Creating an optimized production build ...
✓ Compiled successfully in 2.8s
Linting and checking validity of types ...
Collecting page data ...
✓ Generating static pages (7/7)
Finalizing page optimization ...

Route (app)
┌ ○ /
├ ○ /_not-found
├ ○ /analisis-feedback
├ ○ /login
└ ○ /maintenance-backlog
✓ Exit code: 0
```

### B. Laravel & Database MySQL (`apps/api`)
```bash
php artisan test
```
**Hasil:**
```text
PASS  Tests\Unit\ExampleTest
✓ that true is true

PASS  Tests\Feature\ExampleTest
✓ the application returns a successful response

PASS  Tests\Feature\SchemaSprint1Test
✓ tabel dan kolom delta ada
✓ insert valid berhasil
✓ duplikat source review dalam batch ditolak
✓ ulasan boleh tanpa case
✓ case pending boleh tanpa validator
✓ fk ulasan ke batch tidak dikenal ditolak
- rating di luar rentang ditolak (skipped on sqlite unit test)

Tests: 8 passed, 1 skipped (15 assertions)
Duration: 0.42s
```

**Verifikasi Tabel Database MySQL (`maintaingov`):**
```text
DB: maintaingov connected OK
Tables (13 tables):
  - analysis_batch
  - cache
  - cache_locks
  - failed_jobs
  - job_batches
  - jobs
  - maintenance_case
  - migrations
  - password_reset_tokens
  - pengguna_sistem
  - sessions
  - ulasan
  - users
```

### C. FastAPI Service (`services/analysis`)
```bash
pytest -v
```
**Hasil:**
```text
tests/test_analyze.py::test_health_check PASSED                          [ 25%]
tests/test_analyze.py::test_analyze_valid_request PASSED                 [ 50%]
tests/test_analyze.py::test_analyze_empty_reviews_rejected PASSED        [ 75%]
tests/test_analyze.py::test_analyze_invalid_rating_rejected PASSED       [100%]

======================== 4 passed in 2.09s =========================
```

---

## 4. Acceptance Criteria Checklist (W10-02)

- [x] **Next.js, Laravel, dan FastAPI bisa dijalankan**
- [x] **Laravel berhasil terhubung ke MySQL** (Database `maintaingov` dengan 13 tabel migrasi)
- [x] **Versi runtime dan dependensi dicatat**
- [x] **`.env.example` tersedia tanpa password atau token asli**
- [x] **Langkah instalasi, konfigurasi, dan menjalankan aplikasi ditulis** (`docs/SETUP_GUIDE.md`)
- [ ] **Petunjuk setup berhasil dicoba oleh anggota selain penulis** (Menunggu peer review oleh Demas)

---

## 5. Keamanan & Konfigurasi

- Tidak ada kredensial sensitif, token API, atau password yang di-commit ke Git.
- Semua file `.env`, cache, `.venv`, `node_modules`, dan `vendor` telah dikecualikan di `.gitignore`.
