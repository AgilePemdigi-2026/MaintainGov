# STAGING DEPLOYMENT RUNBOOK — WEEK 15 PREPARATION

**Project:** MaintainGov  
**Target Environment:** Staging (Week 15)  
**PIC:** Muhammad Hanif (`@Haniippp`)  
**Backend Reviewer:** Demas Axel Marfiansyah  
**Frontend Support:** Valisha  
**E2E Support:** Gerard  
**Status Kesiapan:** **PREPARED & CONFIGURED** (Siap dideploy ke infrastruktur staging)  

---

## 1. Arsitektur Layanan Staging

Environment Staging MaintainGov terdiri atas 4 komponen utama:
1. **Frontend App:** Next.js (Port `3000`)
2. **Backend API:** Laravel 11 (Port `8000`)
3. **Analysis Service:** FastAPI / Python (Port `8001`)
4. **Data Layer:** MySQL 8.0/8.4 (Port `3306`) & Redis 7.x (Port `6379`)
5. **Reverse Proxy & SSL:** Nginx dengan Let's Encrypt SSL (Port `80` & `443`)

```mermaid
flowchart TD
    User([Pengguna / GitHub Webhook]) -->|HTTPS:443| Nginx[Nginx Reverse Proxy]
    Nginx -->|/ (Host: staging.maintaingov.id)| Web[Next.js App :3000]
    Nginx -->|/api/* (Host: staging.maintaingov.id)| Api[Laravel API :8000]
    Api -->|Internal HTTP| Py[FastAPI Analysis :8001]
    Api -->|TCP :3306| MySQL[(MySQL Database)]
    Api -->|TCP :6379| Redis[(Redis Cache/Queue)]
    Api -->|Outbound HTTPS| GH[GitHub REST API]
```

---

## 2. Prasyarat Server (Server Prerequisites)

- **OS:** Ubuntu 22.04 LTS atau 24.04 LTS (x86_64)
- **CPU & RAM:** Minimal 2 vCPU, 4 GB RAM, 20 GB SSD
- **Runtimes & Packages:**
  - `php8.3-fpm`, `php8.3-cli`, `php8.3-mysql`, `php8.3-curl`, `php8.3-mbstring`, `php8.3-xml`, `php8.3-zip`, `php8.3-redis`
  - `composer` 2.7+
  - `nodejs` 20.x LTS & `npm` 10+
  - `python3.11` atau `python3.12`, `python3-venv`, `python3-pip`
  - `mysql-server` 8.0+
  - `redis-server`
  - `nginx`
  - `supervisor` (untuk queue worker & FastAPI daemon)

---

## 3. Matriks Variabel Environment Staging

### 3.1 Backend (`apps/api/.env`)
```ini
APP_NAME=MaintainGov
APP_ENV=staging
APP_KEY=base64:GENERATE_KEY_DENGAN_ARTISAN_KEY_GENERATE
APP_DEBUG=false
APP_URL=https://staging.maintaingov.id

LOG_CHANNEL=daily
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=maintaingov_staging
DB_USERNAME=maintaingov_user
DB_PASSWORD=GANTI_DENGAN_PASSWORD_DATABASE_KUAT

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

SANCTUM_STATEFUL_DOMAINS=staging.maintaingov.id
SESSION_DOMAIN=.maintaingov.id

# Konfigurasi Integrasi GitHub Otoritatif
GITHUB_API_URL=https://api.github.com
GITHUB_REPOSITORY_OWNER=AgilePemdigi-2026
GITHUB_REPOSITORY_NAME=MaintainGov
GITHUB_DEFAULT_BRANCH=main
GITHUB_TOKEN=ghp_TOKEN_SERVER_DENGAN_IZIN_CONTENTS_DAN_PR_WRITE
GITHUB_TIMEOUT=15
GITHUB_CONNECT_TIMEOUT=5
GITHUB_WEBHOOK_SECRET=GANTI_DENGAN_SECRET_WEBHOOK_MINIMAL_32_KARAKTER
GITHUB_WEBHOOK_MAX_AGE=900
GITHUB_PR_STALE_SECONDS=60
GITHUB_MOCK_ENABLED=false

# Layanan Analisis FastAPI
ANALYSIS_SERVICE_URL=http://127.0.0.1:8001
```

### 3.2 Frontend (`apps/web/.env.production`)
```ini
NEXT_PUBLIC_API_URL=https://staging.maintaingov.id/api/v1
NEXT_PUBLIC_APP_ENV=staging
```

---

## 4. Langkah-Langkah Deployment (Step-by-Step)

### Langkah 1: Clone & Setup Repositori
```bash
cd /var/www
git clone https://github.com/AgilePemdigi-2026/MaintainGov.git maintain-gov
cd maintain-gov
git checkout main # atau branch release staging
```

### Langkah 2: Setup Database MySQL
```sql
CREATE DATABASE maintaingov_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'maintaingov_user'@'127.0.0.1' IDENTIFIED BY 'PASSWORD_KUAT';
GRANT ALL PRIVILEGES ON maintaingov_staging.* TO 'maintaingov_user'@'127.0.0.1';
FLUSH PRIVILEGES;
```

### Langkah 3: Build & Jalankan Backend API
```bash
cd /var/www/maintain-gov/apps/api
cp .env.example .env
# Edit .env dengan kredensial staging
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

### Langkah 4: Setup Analisis Service (FastAPI)
```bash
cd /var/www/maintain-gov/services/analysis
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```
Konfigurasi Supervisor `/etc/supervisor/conf.d/maintain-analysis.conf`:
```ini
[program:maintain-analysis]
directory=/var/www/maintain-gov/services/analysis
command=/var/www/maintain-gov/services/analysis/.venv/bin/uvicorn main:app --host 127.0.0.1 --port 8001
autostart=true
autorestart=true
user=www-data
stderr_logfile=/var/log/maintain-analysis.err.log
stdout_logfile=/var/log/maintain-analysis.out.log
```

### Langkah 5: Setup Queue Worker
Konfigurasi Supervisor `/etc/supervisor/conf.d/maintain-worker.conf`:
```ini
[program:maintain-worker]
directory=/var/www/maintain-gov/apps/api
command=php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/maintain-worker.log
```
Reload Supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

### Langkah 6: Build & Jalankan Frontend Next.js
```bash
cd /var/www/maintain-gov/apps/web
npm ci
npm run build
```
Jalankan Next.js via PM2 atau Systemd:
```bash
pm2 start npm --name "maintain-web" -- start -- -p 3000
pm2 save
pm2 startup
```

---

## 5. Verifikasi Pasca Deployment (Smoke Testing)

Jalankan pengujian cepat berikut setelah proses deploy:

1. **Health Check Koneksi GitHub:**
   ```bash
   cd /var/www/maintain-gov/apps/api
   php artisan github:check
   ```
   *Ekspektasi:* Output menampilkan `PASS: GitHub repository metadata was read successfully.`

2. **Cek Endpoint Webhook:**
   ```bash
   curl -i -X POST https://staging.maintaingov.id/api/v1/webhooks/github \
     -H "Content-Type: application/json" \
     -H "X-GitHub-Event: ping"
   ```
   *Ekspektasi:* Mengembalikan HTTP 401 jika tanpa signature, atau HTTP 200 jika ditest via GitHub Webhook Settings -> **Redeliver ping**.

3. **Verifikasi Web UI:**
   Buka `https://staging.maintaingov.id` di browser, pastikan halaman login termuat tanpa error CORS / SSL.

---

## 6. Prosedur Rollback

Jika ditemukan kegagalan kritis pada deployment baru:

1. **Rollback Git Commit:**
   ```bash
   cd /var/www/maintain-gov
   git reset --hard HEAD~1
   ```
2. **Rollback Migration (jika diperlukan):**
   ```bash
   cd apps/api
   php artisan migrate:rollback --step=1
   ```
3. **Re-cache & Restart:**
   ```bash
   php artisan config:cache && php artisan route:cache
   sudo supervisorctl restart maintain-worker:*
   pm2 restart maintain-web
   ```
