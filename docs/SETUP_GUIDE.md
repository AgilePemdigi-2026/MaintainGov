# Petunjuk Setup MaintainGov

Dokumen ini menjelaskan cara menyiapkan dan menjalankan seluruh komponen MaintainGov di mesin lokal. Ikuti langkah-langkah di bawah sesuai urutan.

## Daftar Isi

- [Prasyarat](#prasyarat)
- [1. Clone Repository](#1-clone-repository)
- [2. Setup Database (MySQL)](#2-setup-database-mysql)
- [3. Setup Backend (Laravel)](#3-setup-backend-laravel)
- [4. Setup Frontend (Next.js)](#4-setup-frontend-nextjs)
- [5. Setup Layanan Analisis (FastAPI)](#5-setup-layanan-analisis-fastapi)
- [6. Menjalankan Semua Komponen](#6-menjalankan-semua-komponen)
- [7. Verifikasi](#7-verifikasi)
- [Troubleshooting](#troubleshooting)
- [Versi Runtime](#versi-runtime)

---

## Prasyarat

Pastikan perangkat lunak berikut sudah terinstall:

| Perangkat Lunak | Versi Minimum | Cara Cek | Cara Install |
| --- | --- | --- | --- |
| **PHP** | 8.3 | `php --version` | [php.net/downloads](https://www.php.net/downloads) atau via [Laragon](https://laragon.org/download/) (Windows) |
| **Composer** | 2.7+ | `composer --version` | [getcomposer.org](https://getcomposer.org/download/) |
| **Node.js** | 20 LTS+ | `node --version` | [nodejs.org](https://nodejs.org/) |
| **npm** | 10+ | `npm --version` | Otomatis terinstall bersama Node.js |
| **Python** | 3.11+ | `python --version` | [python.org](https://www.python.org/downloads/) |
| **pip** | 23+ | `pip --version` | Otomatis terinstall bersama Python |
| **MySQL** | 8.0+ | `mysql --version` | [dev.mysql.com/downloads](https://dev.mysql.com/downloads/mysql/) atau via Laragon/XAMPP |
| **Git** | 2.40+ | `git --version` | [git-scm.com](https://git-scm.com/) |

### Rekomendasi untuk Windows

Menggunakan **[Laragon](https://laragon.org/download/)** sangat disarankan karena sudah menyediakan PHP, Composer, MySQL, dan Apache/Nginx dalam satu paket. Pilih versi **Laragon Full** yang sudah termasuk PHP 8.3+ dan MySQL 8.

Alternatif lain: **XAMPP** (pastikan versi PHP >= 8.3).

### Ekstensi PHP yang Dibutuhkan Laravel

Pastikan ekstensi berikut aktif di `php.ini`:

```
extension=pdo_mysql
extension=mbstring
extension=openssl
extension=tokenizer
extension=xml
extension=ctype
extension=json
extension=bcmath
extension=fileinfo
extension=curl
```

---

## 1. Clone Repository

```bash
git clone https://github.com/AgilePemdigi-2026/MaintainGov.git
cd MaintainGov
```

---

## 2. Setup Database (MySQL)

### 2a. Jalankan MySQL Server

Jika menggunakan Laragon, klik tombol **Start All** untuk menjalankan MySQL. Jika menggunakan instalasi mandiri, pastikan service MySQL sudah berjalan.

### 2b. Buat Database

Masuk ke MySQL CLI atau gunakan tools seperti phpMyAdmin / MySQL Workbench / DBeaver:

```sql
CREATE DATABASE maintaingov CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

> **Catatan:** Jika MySQL menggunakan password untuk user `root`, catat password tersebut untuk langkah selanjutnya.

---

## 3. Setup Backend (Laravel)

```bash
cd apps/api
```

### 3a. Salin file environment

```bash
cp .env.example .env
```

### 3b. Sesuaikan konfigurasi database di `.env`

Buka file `.env` dan pastikan konfigurasi database sesuai:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=maintaingov
DB_USERNAME=root
DB_PASSWORD=          # Isi jika MySQL menggunakan password
```

### 3c. Install dependencies dan setup

```bash
composer install
php artisan key:generate
```

### 3d. Jalankan migration

```bash
php artisan migrate
```

Jika migration berhasil, tabel-tabel berikut akan terbuat:
- `pengguna_sistem` — data pengguna (Tim Maintenance / Developer)
- `analysis_batch` — batch analisis feedback
- `maintenance_case` — maintenance case hasil analisis
- `ulasan` — ulasan/review dari CSV
- `sessions`, `cache`, `jobs` — tabel pendukung Laravel

### 3e. Verifikasi koneksi database

```bash
php artisan tinker
```

Lalu ketik:
```php
DB::connection()->getPdo();
```

Jika muncul objek PDO tanpa error, koneksi berhasil. Ketik `exit` untuk keluar.

### 3f. Install npm dependencies (untuk asset frontend Laravel jika diperlukan)

```bash
npm install
```

### 3g. Jalankan Laravel

```bash
php artisan serve
```

Laravel akan berjalan di **http://localhost:8000**.

---

## 4. Setup Frontend (Next.js)

Buka terminal baru (biarkan Laravel tetap berjalan):

```bash
cd apps/web
```

### 4a. Salin file environment

```bash
cp .env.example .env.local
```

### 4b. Install dependencies

```bash
npm install
```

### 4c. Jalankan Next.js

```bash
npm run dev
```

Next.js akan berjalan di **http://localhost:3000**.

---

## 5. Setup Layanan Analisis (FastAPI)

Buka terminal baru:

```bash
cd services/analysis
```

### 5a. Buat virtual environment

**Windows (PowerShell):**
```powershell
python -m venv .venv
.\.venv\Scripts\Activate.ps1
```

**Windows (CMD):**
```cmd
python -m venv .venv
.venv\Scripts\activate.bat
```

**macOS / Linux:**
```bash
python3 -m venv .venv
source .venv/bin/activate
```

### 5b. Install dependencies

```bash
pip install -r requirements.txt
```

### 5c. Jalankan FastAPI

```bash
uvicorn main:app --host 0.0.0.0 --port 8001 --reload
```

FastAPI akan berjalan di **http://localhost:8001**. Dokumentasi API otomatis tersedia di **http://localhost:8001/internal/docs**.

---

## 6. Menjalankan Semua Komponen

Untuk menjalankan MaintainGov secara lengkap, buka **3 terminal** dan jalankan masing-masing:

| Terminal | Direktori | Perintah | URL |
| --- | --- | --- | --- |
| 1 - Backend | `apps/api` | `php artisan serve` | http://localhost:8000 |
| 2 - Frontend | `apps/web` | `npm run dev` | http://localhost:3000 |
| 3 - Analysis | `services/analysis` | `uvicorn main:app --port 8001 --reload` | http://localhost:8001 |

> **Penting:** Pastikan MySQL sudah berjalan sebelum memulai Laravel.

### Queue Worker (opsional, diperlukan untuk proses analisis async)

Buka terminal ke-4:
```bash
cd apps/api
php artisan queue:work --tries=3
```

---

## 7. Verifikasi

Setelah semua komponen berjalan, lakukan verifikasi berikut:

### ✅ Laravel API
```bash
curl http://localhost:8000
```
Harus mengembalikan respons (halaman welcome Laravel atau JSON).

### ✅ Next.js Frontend
Buka **http://localhost:3000** di browser. Halaman harus tampil tanpa error.

### ✅ FastAPI Analysis Service
```bash
curl http://localhost:8001/internal/health
```
Harus mengembalikan:
```json
{"status": "ok", "service": "analysis"}
```

### ✅ Koneksi Database
```bash
cd apps/api
php artisan tinker --execute="echo DB::connection()->getDatabaseName();"
```
Harus menampilkan `maintaingov`.

### ✅ Test Endpoint Analisis (opsional)

```bash
curl -X POST http://localhost:8001/internal/v1/analyze ^
  -H "Content-Type: application/json" ^
  -d "{\"schema_version\":\"1.0\",\"batch_id\":1,\"reviews\":[{\"review_id\":1,\"text\":\"Tidak bisa login\",\"rating\":1}]}"
```

---

## Troubleshooting

### PHP tidak dikenali di terminal
- **Windows:** Tambahkan path PHP ke System Environment Variables (`PATH`).
- Jika menggunakan Laragon, gunakan terminal bawaan Laragon yang sudah mengatur PATH otomatis.

### Composer tidak dikenali
- Download installer dari [getcomposer.org](https://getcomposer.org/download/).
- Pastikan `composer.phar` atau `composer.bat` ada di PATH.

### MySQL tidak dikenali di terminal
- Tambahkan path MySQL (biasanya `C:\laragon\bin\mysql\mysql-8.x\bin` atau `C:\Program Files\MySQL\MySQL Server 8.0\bin`) ke PATH.

### `php artisan migrate` gagal — Access denied
- Periksa `DB_USERNAME` dan `DB_PASSWORD` di `.env`.
- Pastikan database `maintaingov` sudah dibuat.

### Port sudah dipakai
- Laravel default: 8000. Gunakan `php artisan serve --port=8080` untuk port lain.
- Next.js default: 3000. Gunakan `npm run dev -- -p 3001` untuk port lain.
- FastAPI default: 8001. Gunakan `--port=8002` pada perintah uvicorn.

### Execution policy error di PowerShell (Windows)
Jika `.venv\Scripts\Activate.ps1` gagal:
```powershell
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser
```

### npm install gagal — node-gyp error
- Pastikan Node.js >= 20.
- Pada Windows, install [Build Tools for Visual Studio](https://visualstudio.microsoft.com/visual-cpp-build-tools/) jika diperlukan.

---

## Versi Runtime

Versi yang digunakan dan diuji untuk project ini:

| Komponen | Versi | File Lockfile |
| --- | --- | --- |
| PHP | 8.3+ | `apps/api/composer.lock` |
| Laravel | 13.17+ | `apps/api/composer.lock` |
| Composer | 2.7+ | - |
| Node.js | 22.x LTS | `apps/web/package-lock.json` |
| Next.js | 15.x | `apps/web/package-lock.json` |
| React | 19.x | `apps/web/package-lock.json` |
| Python | 3.11+ | `services/analysis/requirements.txt` |
| FastAPI | 0.115.x | `services/analysis/requirements.txt` |
| MySQL | 8.0+ | - |

> Versi dependensi dikunci melalui lockfile masing-masing komponen. Jalankan `composer install` / `npm ci` / `pip install -r requirements.txt` untuk mendapatkan versi yang konsisten.

---

## Port Default

| Service | Port | URL |
| --- | --- | --- |
| Laravel API | 8000 | http://localhost:8000 |
| Next.js Web | 3000 | http://localhost:3000 |
| FastAPI Analysis | 8001 | http://localhost:8001 |
| MySQL | 3306 | - |

---

*Dokumen ini adalah bagian dari tugas W10-02. Jika ada kendala instalasi atau perbedaan versi, catat di issue terkait.*
