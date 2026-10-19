# Menjalankan alur UC01 terintegrasi

Layanan utama adalah `services/analysis`; `experiments/uc01-api` merupakan snapshot penelitian lama. Gunakan host **127.0.0.1** pada browser, Laravel dan konfigurasi frontend agar cookie/CORS konsisten.

## Runtime dan artefak

- PHP >=8.3 (lokal diuji 8.4.26), Composer 2, Node >=20, Python **3.13**.
- MySQL atau MariaDB. Bukti lokal memakai MariaDB 10.4.32 dengan driver mysql; Oracle MySQL 8 belum diuji pada perubahan ini.
- Ekstensi PHP: pdo_mysql, mbstring, openssl, fileinfo, curl, zip dan XML/DOM. Untuk tes SQLite, aktifkan pdo_sqlite/sqlite3.
- `upload_tmp_dir` pada php.ini harus menunjuk folder yang tersedia dan dapat ditulis. Atur `upload_max_filesize=8M`, `post_max_size=10M`; apit path berspasi dengan tanda kutip.
- Artefak privat dari hasil Colab tepercaya: `sentiment_pipeline.joblib`, `slang_map.json`, `domain_rules.json`. Jangan commit artefak atau memuat joblib dari sumber yang tidak dipercaya.

## Laravel

Siapkan database khusus proyek dan akun database lokal, lalu dari root repository:

```powershell
cd apps/api
Copy-Item .env.example .env
composer install
php artisan key:generate
```

Sesuaikan konfigurasi DB pada `.env`. Migration menggunakan tabel proyek yang sudah ada dan menambahkan kolom integrasi; tidak perlu menghapus database.

```dotenv
APP_URL=http://127.0.0.1:8000
FRONTEND_URL=http://127.0.0.1:3000
ANALYSIS_SERVICE_URL=http://127.0.0.1:8001
ANALYSIS_TIMEOUT=120
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=240
SESSION_DRIVER=database
```

```powershell
php artisan migrate
php artisan db:seed
php artisan serve --host=127.0.0.1 --port=8000
```

Seeder menyediakan akun uji `maintenance@maintaingov.test` dan `developer@maintaingov.test`; password mengikuti `SEED_TEST_PASSWORD` (contoh lokal `password123`). Akun contoh hanya untuk demo lokal. Jangan commit `.env`.

Pada terminal kedua, di `apps/api`:

```powershell
php artisan queue:work --sleep=1 --tries=1 --timeout=180
```

Worker wajib hidup; restart setelah perubahan source/config. `retry_after` harus lebih besar daripada timeout worker.

## FastAPI

Dari root repository:

```powershell
cd services/analysis
py -3.13 -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
```

Buat folder `artifacts` dan salin tiga artefak privat ke dalamnya. scikit-learn wajib 1.6.1. Clone tanpa artefak belum siap menjalankan model; startup sengaja gagal jika model tidak tersedia.

```powershell
$env:UC01_ARTIFACT_DIR = (Resolve-Path .\artifacts).Path
.\.venv\Scripts\python.exe -m uvicorn main:app --host 127.0.0.1 --port 8001
```

Health: `/internal/health`; dokumentasi: `/internal/docs`. Jika port 8001 dipakai prototipe lama, pilih port kosong, samakan `ANALYSIS_SERVICE_URL`, dan restart worker Laravel. File `.env.example` FastAPI tidak otomatis dimuat oleh perintah ini; variabel artefak diset eksplisit seperti di atas.

## Next.js

Terminal baru dari root:

```powershell
cd apps/web
Copy-Item .env.example .env
npm ci
npm run typecheck
npm run build
npm run start -- --hostname 127.0.0.1
```

Buka http://127.0.0.1:3000/login. Login sebagai Maintenance, unggah `services/analysis/examples/reviews.csv` dan jalankan analisis. Kolom CSV: `reviewId`, `Review Teks`, `rating`, `date`; tanggal `YYYY-MM-DD HH:MM:SS`. Hasil Selesai menampilkan ulasan dan kandidat case Pending; URL `?batch=<id>` mempertahankan hasil setelah refresh. Developer diarahkan ke halaman backlog persiapan Sprint 2.

## Pemeriksaan

Pada `apps/api`, `php artisan test` memakai SQLite in-memory sesuai phpunit.xml. Tes constraint rating khusus mysql dilewati pada SQLite. Untuk suite mysql, gunakan database uji terpisah dan set environment DB khusus terminal tes. RefreshDatabase menyiapkan ulang skema database tes: jangan arahkan ke database demo/produksi.

Pada `services/analysis`, setelah model tersedia:

```powershell
.\.venv\Scripts\python.exe -m pytest -q tests
```

Dari root saat layanan dan worker hidup:

```powershell
python scripts/smoke_uc01_http.py
```

Script memakai fixture tersamar dan akun seeder, lalu membuat batch demo. Atur `MAINTAINGOV_DEMO_PASSWORD` bila password seed berbeda. Script memeriksa CSRF, input salah, RBAC, hasil tersimpan, idempotensi, logout dan replay cookie lama. Ini bukan uji kualitas model terhadap label manusia.

## Kendala umum

- Refused to connect: server pada port tersebut belum hidup.
- Diproses terus: periksa worker dan database queue.
- Gagal: periksa health model dan library, hidupkan FastAPI lalu retry.
- 419/CORS: samakan host 127.0.0.1 dan FRONTEND_URL; periksa cookie/token CSRF.
- File failed to upload: periksa folder sementara, batas upload, dan izin pada runtime PHP yang digunakan.

Lihat [catatan integrasi dan bukti](UC01_INTEGRATION.md) untuk batas status penerimaan.
