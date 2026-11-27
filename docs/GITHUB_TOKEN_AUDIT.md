# Audit GitHub Token — Runbook Domain (Coolify)

Dokumen ini dipakai untuk memeriksa mengapa integrasi GitHub gagal di deployment
Coolify, khususnya gejala **"klik Verifikasi GitHub malah keluar ke halaman login"**.

Runbook ini bisa dijalankan langsung terhadap domain publik, lalu dilanjutkan
dengan pemeriksaan di dalam container `api`/`queue`.

- **Domain contoh:** `https://maintaingov.example.com`
- **Base API:** `https://<domain>/api/v1`
- **Repository target:** `AgilePemdigi-2026/MaintainGov`
- **Jangan pernah menempelkan nilai token ke dokumen, chat, issue, atau screenshot.**

---

## 0. Ringkasan akar masalah

Gejala "keluar" **bukan berarti sesi login benar-benar habis**. Alurnya:

1. Token GitHub ada, tetapi **ditolak GitHub** (401).
2. Sebelum perbaikan, backend meneruskan status 401 itu apa adanya:
   - `apps/api/app/Services/GitHub/GitHubService.php` (`classifyResponse`, `failureFromResponse`)
     → `GITHUB_UNAUTHORIZED` dengan HTTP 401.
   - `apps/api/app/Http/Controllers/GitHubConnectionController.php:35-48`
     → response 401 ke browser.
   - `apps/api/app/Services/GitHub/GitHubErrorMapper.php:21`
     → status GitHub juga diteruskan pada verifikasi push / pembuatan PR.
3. Frontend lama menganggap **semua 401 = sesi kedaluwarsa**:
   - `apps/web/src/components/workspace/session.tsx`
     → `router.replace("/login")`.
4. Tombol yang memicunya:
   - `apps/web/src/lib/sprint2/client.ts` (`checkConnection`, halaman Ajukan PR)
   - `apps/web/src/lib/sprint2/client.ts` (`verifyPush`, tombol Verifikasi push GitHub)

**Perbaikan sudah diterapkan:** backend sekarang memetakan 401 upstream GitHub
menjadi **HTTP 502** dengan kode `GITHUB_UNAUTHORIZED`, dan frontend tidak lagi
menganggap kode `GITHUB_*` sebagai sesi habis. Lihat bagian 6.

Jadi urutan diagnosis yang benar:

```
Token tidak sampai ke container?  -> 503 / BLOCKED
Token sampai tapi ditolak?        -> 502 GITHUB_UNAUTHORIZED  (dulu 401, memicu logout)
Token valid tapi izin kurang?     -> 403 GITHUB_FORBIDDEN
Token valid dan izin cukup?       -> 200 connected
```

---

## 1. Audit cepat dari browser (paling praktis)

Lakukan di domain asli, bukan localhost.

1. Login sebagai **Tim Maintenance** atau **Developer**.
2. Buka DevTools → **Console**.
3. Jalankan:

```js
const r = await fetch("/api/v1/github/connection", {
  credentials: "include",
  headers: { Accept: "application/json" },
});
console.log("HTTP", r.status);
console.log(await r.json());
```

4. Baca hasilnya dengan tabel interpretasi di bagian 5.

Jika ingin menguji tombol **Verifikasi push GitHub** (butuh role Developer dan
issue yang di-assign ke akun tersebut), jalankan di Console:

```js
const id = 123; // ganti dengan backlog_id / issue_id yang sedang dibuka

const csrf = await (await fetch("/api/v1/auth/csrf", {
  credentials: "include",
  headers: { Accept: "application/json" },
})).json();

const r = await fetch(`/api/v1/maintenance-backlogs/${id}/push-verifications`, {
  method: "POST",
  credentials: "include",
  headers: {
    Accept: "application/json",
    "Content-Type": "application/json",
    "X-CSRF-TOKEN": csrf.token,
  },
  body: "{}",
});

console.log("HTTP", r.status);
console.log(await r.json());
```

Yang dicari di body:

```json
{
  "success": false,
  "message": "GitHub authentication failed.",
  "error": { "code": "GITHUB_UNAUTHORIZED", "message": "GitHub authentication failed." }
}
```

Jika muncul kode itu, token bermasalah — bukan sesi aplikasi.

> Catatan: di DevTools → Network, request yang memicu masalah biasanya
> `/api/v1/github/connection` atau `/api/v1/maintenance-backlogs/<id>/push-verifications`.
> Setelah patch, statusnya **502** dengan `error.code = GITHUB_UNAUTHORIZED`.
> Deployment lama yang belum di-redeploy masih bisa mengembalikan **401**;
> frontend baru tetap tidak akan logout karena membaca `error.code`.

---

## 2. Audit HTTP dari terminal (curl / PowerShell)

Login dibatasi **10 percobaan per menit per IP**. Jangan mengulang login terus-menerus.

### 2.1 Bash / curl

```bash
BASE="https://<domain>"
EMAIL="maintenance@maintaingov.test"
PASSWORD="<password-seed>"
JAR="$(mktemp)"

# 1) Buat sesi + ambil CSRF token
CSRF="$(curl -sS -c "$JAR" "$BASE/api/v1/auth/csrf" | jq -r .token)"
echo "csrf length: ${#CSRF}"

# 2) Login (cookie sesi diperbarui di JAR)
curl -sS -b "$JAR" -c "$JAR" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-TOKEN: $CSRF" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}" \
  "$BASE/api/v1/auth/login"

# 3) Cek identitas sesi
curl -sS -b "$JAR" -H "Accept: application/json" "$BASE/api/v1/auth/me"

# 4) Audit koneksi GitHub (ini yang penting)
curl -sS -i -b "$JAR" -H "Accept: application/json" \
  "$BASE/api/v1/github/connection"
```

### 2.2 PowerShell 7+

```powershell
$base     = "https://<domain>"
$email    = "maintenance@maintaingov.test"
$password = "<password-seed>"

$session = $null
$csrf = Invoke-RestMethod -Uri "$base/api/v1/auth/csrf" -Method Get -SessionVariable session

$null = Invoke-RestMethod -Uri "$base/api/v1/auth/login" -Method Post -WebSession $session `
  -Headers @{ "X-CSRF-TOKEN" = $csrf.token; "Accept" = "application/json" } `
  -ContentType "application/json" `
  -Body (@{ email = $email; password = $password } | ConvertTo-Json)

$response = Invoke-WebRequest -Uri "$base/api/v1/github/connection" `
  -Method Get -WebSession $session -Headers @{ "Accept" = "application/json" } `
  -SkipHttpErrorCheck

"HTTP $($response.StatusCode)"
$response.Content
```

Jika memakai Windows PowerShell 5.1 (tanpa `-SkipHttpErrorCheck`), bungkus dengan
`try/catch` dan baca `$_.Exception.Response.StatusCode`.

### 2.3 Cek endpoint tanpa sesi (kontrol)

```bash
curl -sS -o /dev/null -w "%{http_code}\n" \
  "https://<domain>/api/v1/github/connection"
```

Harus **401**. Kalau bukan 401, ada masalah routing/auth yang berbeda.

---

## 3. Audit di dalam container Coolify

Buka **Coolify → service `api` → Terminal**. Jalankan berurutan.

### 3.1 Pastikan env benar-benar masuk

```bash
# Panjang token setelah trim (0 = tidak ada)
php -r 'echo "GITHUB_TOKEN length: ".strlen(trim((string) getenv("GITHUB_TOKEN"))).PHP_EOL;'

# Cek nilai config Laravel (jangan cetak token-nya)
php artisan tinker --execute 'dump(strlen((string) config("services.github.token")));'
```

Keduanya harus sama dan **> 0**.

### 3.2 Pastikan tidak ada config cache basi

```bash
ls -l bootstrap/cache/config.php 2>/dev/null || echo "no config cache"
```

Kalau file itu ada dan dibuat sebelum token diisi:

```bash
php artisan config:clear
php artisan config:cache
```

### 3.3 Jalankan pemeriksaan resmi aplikasi

```bash
php artisan github:check
```

Contoh hasil dan artinya:

| Output | Arti |
|---|---|
| `BLOCKED: GITHUB_TOKEN belum dikonfigurasi` | Env tidak sampai ke container ini |
| `FAIL: status=unauthorized http=502 code=GITHUB_UNAUTHORIZED` | Token ditolak GitHub (invalid/expired/salah paste); HTTP asli dari GitHub 401, dipetakan ke 502 |
| `FAIL: status=forbidden http=403 code=GITHUB_FORBIDDEN` | Token valid, izin repo kurang |
| `FAIL: status=not_found http=404 code=GITHUB_REPOSITORY_NOT_FOUND` | Owner/repo salah atau token tidak melihat repo |
| `FAIL: status=rate_limited http=429 code=GITHUB_RATE_LIMITED` | Rate limit; tunggu lalu ulangi |
| `PASS: GitHub repository metadata was read successfully.` | Token dan izin baca valid |

### 3.4 Uji token langsung ke GitHub dari container

```bash
curl -sS -o /dev/null -w "%{http_code}\n" \
  -H "Authorization: Bearer $GITHUB_TOKEN" \
  https://api.github.com/repos/AgilePemdigi-2026/MaintainGov
```

| HTTP | Arti |
|---:|---|
| `200` | Token valid dan repo terlihat |
| `401` | Token invalid/expired/salah format |
| `403` | Izin kurang / SSO organisasi belum diotorisasi |
| `404` | Repo tidak ditemukan atau tidak terlihat oleh token |

### 3.5 Cek container `queue`

Integrasi GitHub juga dipakai worker. Ulangi pengecekan env di service `queue`:

```bash
php -r 'echo strlen(trim((string) getenv("GITHUB_TOKEN"))).PHP_EOL;'
```

Kalau `api` punya token tetapi `queue` tidak, operasi latar belakang akan gagal.

---

## 4. Checklist konfigurasi Coolify

- [ ] `GITHUB_TOKEN` diisi di **Environment Variables resource/stack**, bukan hanya build variable.
- [ ] Variabel tersedia untuk service **`api`** dan **`queue`**.
- [ ] Setelah mengubah env, klik **Redeploy / Force recreate**, bukan sekadar Restart.
      Compose membaca `${GITHUB_TOKEN:-}` saat deploy.
- [ ] Token **fine-grained PAT** (bukan classic PAT):
  - Repository access: `AgilePemdigi-2026/MaintainGov`
  - Metadata: **Read**
  - Contents: **Read and write**
  - Pull requests: **Read and write**
- [ ] Token belum **expired** dan belum **di-revoke**.
- [ ] Jika organisasi memakai SSO, token sudah **authorized** untuk organisasi.
- [ ] Nilai token tidak mengandung:
  - prefix `Bearer `
  - tanda kutip `"..."` atau `'...'`
  - spasi/newline di awal atau akhir
- [ ] `GITHUB_REPOSITORY_OWNER=AgilePemdigi-2026`
- [ ] `GITHUB_REPOSITORY_NAME=MaintainGov`
- [ ] `GITHUB_API_URL=https://api.github.com`
- [ ] `GITHUB_MOCK_ENABLED=false` di production
- [ ] `APP_URL` sama persis dengan domain HTTPS yang dipakai browser
      (skema, host, tanpa trailing slash).
- [ ] Tidak ada `bootstrap/cache/config.php` yang basi.
- [ ] Log `api`/`queue` tidak memuat token (cek juga `storage/logs`).

---

## 5. Matriks interpretasi hasil

| Hasil audit | Penyebab | Tindakan |
|---|---|---|
| `GET /github/connection` → 200 `connected` | Token valid | Tidak ada masalah token |
| → 502 `GITHUB_UNAUTHORIZED` | Token invalid/expired/salah paste | Rotasi token, paste ulang tanpa prefix/quotes, redeploy |
| → 401 `GITHUB_UNAUTHORIZED` (deployment lama) | Token invalid/expired/salah paste | Rotasi token + redeploy patch backend/frontend |
| → 403 `GITHUB_FORBIDDEN` | Izin repo kurang | Perbaiki permission fine-grained PAT |
| → 404 `GITHUB_REPOSITORY_NOT_FOUND` | Owner/repo salah atau tidak terlihat | Cek env owner/repo dan repository access token |
| → 429 `GITHUB_RATE_LIMITED` | Rate limit GitHub | Tunggu sesuai `Retry-After` |
| → 503 `GITHUB_NOT_CONFIGURED` | Token tidak terbaca Laravel | Cek env, config cache, dan service yang dipakai |
| → 503 `GITHUB_UNAVAILABLE` | Gagal konek ke GitHub | Cek DNS/firewall/egress container |
| → 401 tanpa body GitHub | Sesi aplikasi memang habis | Login ulang; cek `SESSION_*` dan `APP_URL` |
| → 419 | CSRF/sesi kedaluwarsa | Login ulang; pastikan cookie same-origin |
| Container `api`/`queue` mati | Bukan efek token | Cek log container, `APP_KEY`, DB, healthcheck |

---

## 6. Perbaikan yang sudah diterapkan

Masalah desain status code: **401 milik GitHub disamakan dengan 401 milik sesi aplikasi.**
Keduanya sudah diperbaiki.

### Backend (sudah diterapkan)

- `apps/api/app/Services/GitHub/GitHubService.php`
  - Konstanta `UPSTREAM_AUTH_FAILURE_HTTP_STATUS = 502`.
  - `classifyResponse()` dan `failureFromResponse()` memetakan 401 GitHub → **502**.
  - Kode `GITHUB_UNAUTHORIZED` tetap dipertahankan agar mudah didiagnosis.
- `apps/api/app/Services/GitHub/GitHubErrorMapper.php`
  - Otomatis meneruskan 502 tersebut ke endpoint verifikasi push dan pembuatan PR.
- Test yang diperbarui:
  - `apps/api/tests/Feature/GitHubConnectionTest.php`
  - `apps/api/tests/Unit/GitHubServiceTest.php`
  - `apps/api/tests/Feature/PushVerificationTest.php`
- Guest tanpa sesi **tetap mendapat 401** dari middleware `auth`
  (`test_connection_check_rejects_guest`), jadi semantik 401 aplikasi tidak berubah.

### Frontend (sudah diterapkan)

- `apps/web/src/lib/api-client.ts`
  - `ApiError` sekarang menyimpan `error.code` dari envelope API.
- `apps/web/src/components/workspace/session.tsx`
  - `isGitHubError()` mendeteksi kode `GITHUB_*`.
  - Redirect `/login` hanya untuk **419** atau **401 non-GitHub**.
  - Pesan kode `GITHUB_*` dipetakan ke kalimat Indonesia (mis. token tidak valid).
- `apps/web/src/app/(workspace)/maintenance-issue/[id]/pull-request/page.tsx`
  - Error koneksi GitHub ditampilkan sebagai `ErrorState` + tombol **Coba lagi**.
  - 404 `GITHUB_REPOSITORY_NOT_FOUND` tidak lagi disalahartikan sebagai
    "Maintenance Issue tidak ditemukan".

### Regression test (sudah ditambahkan)

- `apps/web/tests/e2e/sprint2-issue-pr.spec.ts`
  - `PR form: GitHub 401 shows token error and does not log out`
  - `verify push: GitHub 401 shows token error and keeps the session`
- Test PHPUnit memastikan GitHub 401 → 502, sedangkan request tanpa sesi → 401.
- Verifikasi push dengan token invalid menampilkan pesan error dan tidak mengubah
  status issue (perilaku lama tetap dipertahankan).

---

## 7. Format bukti audit

Isi setelah audit dijalankan. Simpan di issue/PR, bukan di chat.

```md
### GitHub Token Audit — <tanggal>

- Domain: https://<domain>
- Commit/SHA deployed: <sha>
- Operator: <nama>
- Waktu audit (WIB): <yyyy-mm-dd hh:mm>

| Pemeriksaan | Perintah | Hasil |
|---|---|---|
| Env token di `api` | `php -r '...getenv("GITHUB_TOKEN")...'` | length=<n> |
| Env token di `queue` | idem | length=<n> |
| Config cache | `ls bootstrap/cache/config.php` | ada / tidak |
| `github:check` | `php artisan github:check` | PASS / FAIL <kode> |
| GitHub langsung | `curl -o /dev/null -w "%{http_code}" ...` | <200/401/403/404> |
| Endpoint domain | `GET /api/v1/github/connection` | <status + code> |
| Logout saat verifikasi | DevTools Network | ya / tidak |

Kesimpulan:
- [ ] Token belum masuk container
- [ ] Token ditolak GitHub (401)
- [ ] Izin kurang (403)
- [ ] Repo/owner salah (404)
- [ ] Bug mapping 401 frontend
- [ ] Lain: <...>

Tindak lanjut:
- <langkah + PIC + tenggat>
```

---

## 8. Keamanan

- Jangan pernah menjalankan `echo $GITHUB_TOKEN`, `cat .env`, atau mencetak token
  ke terminal bersama/screenshot.
- Jika token sempat terekspos (chat, log, commit, screenshot), **revoke dan
  generate ulang** di GitHub → Settings → Developer settings → Fine-grained tokens.
- Setelah rotasi: update Coolify → Redeploy → ulangi bagian 3.
- Jangan mengaktifkan `APP_DEBUG=true` di domain publik.
- Jangan menjalankan `migrate:fresh`, `db:wipe`, atau `db:seed --force` di staging
  yang sudah berisi data hanya untuk menguji token.

---

## 9. Urutan eksekusi singkat (TL;DR)

```text
1. Browser Console  -> GET /api/v1/github/connection  -> catat status + error.code
2. Coolify api      -> php artisan github:check       -> catat PASS/FAIL
3. Coolify api      -> cek panjang env token + config cache
4. Coolify api      -> curl langsung ke api.github.com/repos/... -> catat HTTP
5. Ulangi cek env di service queue
6. Patch 401 sudah diterapkan; redeploy commit terbaru agar backend/frontend memakai 502 + error.code
7. Jika 502/403/404 -> perbaiki token/izin -> redeploy -> ulangi dari langkah 1
8. Isi format bukti (bagian 7) dan lampirkan di issue
```
