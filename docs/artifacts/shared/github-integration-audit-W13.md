# Audit Integrasi GitHub — W13 (UC04→UC05)

Tanggal: 2026-11-09 | Mode: read-only audit | Eksekusi: agen (di atas nama tim)
Lingkup: identifikasi lapis penyebab `GitHub is temporarily unavailable.` pada tombol
"Verifikasi push GitHub" & "Ajukan pull request" (halaman Maintenance Issue BKLG-0001, role Developer).
Batas: **tidak ada kode/config/.env yang diubah, tidak ada secret dicetak, tidak ada commit.**

---

## 0. Ringkasan eksekutif

Akarnya **bukan kode aplikasi, bukan flag mock, dan bukan jaringan**: PHP cURL Laragon
membaca CA bundle dari `curl.cainfo` di `php.ini` yang masih menunjuk **instalasi Laragon
lama (`D:\Projects\Laragon-installer\8.0-W64\etc\ssl\cacert.pem`) yang sudah tidak ada**.
Setiap call HTTPS (termasuk `https://api.github.com`) langsung gagal pada **cURL error 77**
("error setting certificate file"), tertangkap `catch (\Throwable)` di `GitHubService`
dan dimap menjadi `GITHUB_UNAVAILABLE` → HTTP 503 `"GitHub is temporarily unavailable."`.

Flag mock GitHub **tetap aktif** setelah insiden restart server (hipotesis LANGKAH 4 gugur).

---

## 1. Peta kode integrasi GitHub

| Lapis | Lokasi | Isi |
| --- | --- | --- |
| Route nyata | `apps/api/routes/github.php:10-43` | `GET /github/connection`; `POST /maintenance-backlogs/{id}/claim, /push-verifications`; `GET .../push-status`, `/branches`, `/pull-requests`; `POST .../pull-requests`, `/pull-requests/reconcile`; `POST /webhooks/github` (HMAC, tanpa sesi) |
| Route mock | `routes/github.php:45-53` | `/developer/github-mock/*` + middleware alias `github.mock` (`EnsureGitHubMockEnabled`) |
| Middleware mock | `app/Http/Middleware/EnsureGitHubMockEnabled.php:13` | `abort_unless(config('services.github.mock_enabled'), 404)` — aktif bila `GITHUB_MOCK_ENABLED=true` **atau default** `APP_ENV ∈ {local, testing}` |
| Adapter | `app/Services/GitHub/GitHubService.php` | satu titik: auth bearer, timeout (`services.github.timeout=10`, `connect_timeout=5`), header API version, `classifyResponse`, validasi payload. Koneksi: `checkConnection()` (baris 29-64); `configuration()` (284-303) — wajib token+owner+repo+api_url non-kosong |
| Mapper error | `app/Services/GitHub/GitHubErrorMapper.php:13-25` | hasil adapter → `ApiException` tanpa bocor detail |
| Service UC04 | `app/Services/GitHub/PushVerificationService.php:30-85` | baca head branch + commit dari API (jangan percaya frontend), lalu `markPushVerified` |
| Config | `apps/api/config/services.php` (blok `github`) | kunci `.env` yang dibaca: `GITHUB_API_URL`, `GITHUB_REPOSITORY_OWNER`, `GITHUB_REPOSITORY_NAME`, `GITHUB_TOKEN`, `GITHUB_TIMEOUT`, `GITHUB_CONNECT_TIMEOUT`, `GITHUB_DEFAULT_BRANCH`, `GITHUB_WEBHOOK_SECRET`, `GITHUB_WEBHOOK_MAX_AGE`, `GITHUB_PR_STALE_SECONDS`, `GITHUB_MOCK_ENABLED` |

## 2. Temuan config/env (nama kunci + ada/tidak — TANPA nilai)

| Kunci | `.env` | Kefektifan runtime (konfig) |
| --- | --- | --- |
| `GITHUB_TOKEN` | **ADA** | stream berformat PAT fine-grained (panjang 93 char) — validitas tidak bisa diuji karena transport SSL selalu gagal |
| `GITHUB_REPOSITORY_OWNER` | ADA | `AgilePemdigi-2026` (non-secret) |
| `GITHUB_REPOSITORY_NAME` | ADA | `MaintainGov` (non-secret) |
| `GITHUB_API_URL` | tidak ada (default `https://api.github.com`) | default dipakai |
| `GITHUB_MOCK_ENABLED` | **TIDAK ADA** | default dari `APP_ENV` → **mock aktif** (`mock_enabled=true`, `APP_ENV=local`) |
| `GITHUB_WEBHOOK_SECRET` | tidak ada (default null) | webhook dinonaktifkan aman (reject, bukan accept) |
| `APP_ENV` | ADA | `local` |

> **CATATAN proses**: server `:8000` yang berjalan saat audit adalah server yang direstart
> **dengan env bersih** pada perbaikan login kemarin — `artisan serve` memuat `.env` penuh,
> **tidak ada env override DB_/GITHUB_* dari shell** (dibuktikan db default & mock aktif).

## 3. Matriks reproduksi (sesi Developer, HTTP :8000)

| Endpoint | Status | Pesan/envelope | Lapis yang gagal |
| --- | --- | --- | --- |
| `GET /api/v1/github/connection` | **503** | `{success:false, message:"GitHub is temporarily unavailable.", error:{code:"GITHUB_UNAVAILABLE"}}` | transport TLS cURL (CA path) — config layanan OK (bukan `GITHUB_NOT_CONFIGURED`) |
| `GET /api/v1/maintenance-backlogs/1/push-status` | 200 | status `not_detected`, branch `maintenance/issue-1` | ✅ (endpoint ini tidak menyentuh GitHub) |
| `POST /api/v1/maintenance-backlogs/1/push-verifications` | **503** | `GITHUB_UNAVAILABLE` — "GitHub is temporarily unavailable." | sama: `getBranch()` → cURL 77 |
| `POST /api/v1/maintenance-backlogs/1/pull-requests` (body valid) | **409** | `ISSUE_NOT_READY_FOR_PULL_REQUEST` | GUARD BISNIS yang benar — alur UC05 menunggu push terverifikasi dulu; tanpa push OK tidak bisa lanjut |
| `POST /developer/github-mock/issues` (GET) | **200** | daftar 3 issue `is_mock:true, mock_source:uc04-uc05-fixtures` | ✅ mock aktif & sehat |
| `GET /developer/github-mock/issues/1/push-status` | 404 | `ISSUE_NOT_FOUND` (data mock mulai id 101) | ✅ idempoten sesuai fixture |
| `POST /developer/github-mock/issues/102/pull-requests` | 422 | validasi "Kolom branch sumber wajib diisi." — **artefak harness audit** (body tidak sampai controller pada beberapa mode pengiriman; endpoint nyata dengan body file tetap 418/409 benar) | bukan bug aplikasi; stub dikonfirmasi aktif lewat GET |
| curl.exe² langsung `https://api.github.com/repos/...` | **200** | — | jaringan/dns/TLS **OK** di luar PHP |

## 4. Bukti akar masalah (log & probe PHP)

`storage/logs/laravel.log` **tidak memuat jejak kegagalan GitHub runtime** — `GitHubService`
menyerap exception transport tanpa `Log::` (temuan observabilitas, lihat §6). Rekonstruksi akar
suber dibuktikan langsung via tinker (CLI, same php.ini, token TIDAK dicetak):

```text
TRANSPORT(no-auth): ConnectionException cURL error 77: error setting certificate file:
  D:\Projects\Laragon-installer\8.0-W64\etc\ssl\cacert.pem

SVC: ConnectionException cURL error 77: ... (sama, saat memakai token aplikasi)
```

- `php --ini` → `Loaded Configuration File: C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.ini`
- Di php.ini tersebut: `curl.cainfo = D:\Projects\Laragon-installer\8.0-W64\etc\ssl\cacert.pem`
- Path tersebut **TIDAK ADA** (`Test-Path` → False); CA Laragon yang valid ada di
  `C:\laragon\etc\ssl\cacert.pem`.
- `GitHubService.php:336-348` (`callAndValidate`) dan `:44-61` (`checkConnection`):
  `catch (ConnectionException)` / `catch (\Throwable)` → `GITHUB_UNAVAILABLE` + English
  message → session frontend menampilkan `error.message` apa adanya
  (`apps/web/src/components/workspace/session.tsx:24-28`).

Rantai: **php.ini CA path mati → cURL 77 semua HTTPS → adapter menyerap →
GITHUB_UNAVAILABLE → banner**. Tombol "Ajukan PR" terlihat gagal karena guard 409
(tidak bisa lanjut tanpa push verified) — bukan lapis GitHub-nya.

## 5. Hipotesis alternatif yang digugurkan

| Hipotesis | Bukti gugur |
| --- | --- |
| Flag mock hilang saat server direstart (insiden `DB_*` kemarin) | `GET /developer/github-mock/issues` → 200 `is_mock:true`; konfig `mock_enabled=true` (default `APP_ENV=local`); route mock hidup |
| Jaringan/firewall ke api.github.com | curl.exe (Schannel) → HTTP 200; DNS+TLS normal |
| Token tidak ada (GITHUB_NOT_CONFIGURED) | kunci ADA di .env; pesan yang muncul justru GITHUB_UNAVAILABLE (bukan GITHUB_NOT_CONFIGURED) |
| DB salah (serupa insiden login) | `migrate:status` semua Ran; push-status endpoint baca data benar (backlog 1, branch `maintenance/issue-1`) |
| Konfig server lama vs runtime | GET /github/connection & POST push-verifications konsisten 503; probe tinker (same php.ini) identik |

## 6. Temuan sekunder (observabilitas & kebijakan bahasa)

1. **Tidak ada jejak log**: `catch (\Throwable)` di `GitHubService` tidak memanggil `Log::`
   — 503 produksi tidak meninggalkan bukti penyebab. Rekomendasi (ranah kode, W13-02 Hanif):
   catat `Log::warning('github transport disabled', ['code' => GITHUB_UNAVAILABLE])`
   tanpa detail exception/credential.
2. **Pesan user berbahasa Inggris**: `"GitHub is temporarily unavailable."` muncul
   6× di `GitHubService.php` (51,59,339,345,396,515) + fallback `PullRequestService.php:310`
   + fixture kontrak `contracts/fixtures/uc04-uc05/connection-unavailable.json`. Frontend
   menampilkan `error.message` mentah → melanggar kebijakan pesan user Bahasa Indonesia
   (SRS §3.4.5) yang sudah dipatuhi lapis lain (`credentials salah`, sikap 401/403, & error
   validasi). Kotak: bila diterjemahkan per 1 tempat, sinkronkan kontrak fixture juga.
3. **Prefix `/developer/github-mock/issues/{id}` mulai id 101-103** sementara backlog nyata
   BKLG-0001 = `backlog_id 1` — dua donain dunia angka; pastikan UI tidak salah taut antara
   mock dan data nyata saat demo (temuan kecil, bukan penyebab 503).

## 7. Kepemilikan & dampak

| Hal | Milik | Status |
| --- | --- | --- |
| **Konfig mesin: `curl.cainfo` di php.ini Laragon menunjuk instalasi lama yang hilang** | Konfigurasi lokal/laptop (bukan repo) — perbaikan **satu baris**: arahkan ke `C:\laragon\etc\ssl\cacert.pem` | **PENYEBAB 503**; di luar ranah W13-02 karena php.ini bukan file repo |
| Verifikasi live GitHub (cek validitas PAT & izin repo) | W13-02 Hanif (blokir sampai CA beres) | akan lulus/401 tepat setelah transport beres — butuh PAT ber-scope |
| Observabilitas + terjemahan pesan errors (bila disepakati PO) | W13-02/improvement kode | opsional, tidak menghalangi alur |
| Aturan block W13-04 | — | **Integrasi live tetap "Blocked"** — dilarang klaim Done hanya karena mock UI jalan; sesuai catatan W13-04 |

**Dampak demo/acceptance Sprint 2**: alur UC04→UC05 dapat didemo **penuh via mock endpoint**
(`/developer/github-mock/*` hidup, `is_mock:true`) TANPA dependensi jaringan; integrasi live
**tidak dapat** didemokan sampai config CA dibenahi & keabsahan PAT (opsi B) dikonfirmasi.
Penerimaan perlu menyatakan eksplisit "mock" vs "live" agar tidak dihitung Done palsu
(pelajaran segel wet-rul W12-02-01).

## 8. Opsi perbaikan

| Opsi | Isi | Effort | Risiko |
| --- | --- | --- | --- |
| **A (rekomendasi, segera)** | Perbaiki `curl.cainfo` di `php.ini` (`C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.ini` → `C:\laragon\etc\ssl\cacert.pem`); restart `artisan serve` + `queue:work`. Satu baris config mesin, bukan repo. | ~5 menit | rendah; menyembuhkan SEMUA HTTPS dari PHP lokal (composer, other) — perlu konfirmasi tim di laptop lain juga |
| B | Siapkan PAT fine-grained ber-scope repoMaintainGov (koordinasi Hanif/PO), lalu verifikasi live `GET /connection` → 200 | menengah (koordinasi) | rb: token bocor di .env — tetap pastikan jangan pernah enters log/commit; dark-check .gitignore `.env` (sudah diabaikan git) |
| C | Tambahkan `Log::warning` pada catch `GitHubService` + (opsional) terjemahkan pesan GITHUB_* ke Bahasa Indonesia + sinkron fixture kontrak | kecil-menengah | rendah; hanya untuk observabilitas — tidak menyembuhkan transport |
| D (dikenal) | Mock untuk demo dev lokal | sudah aktif | mock ≠ live; boleh for demo, jangan diklaim Done |

## 9. Catatan teknis reproduksi Harness (untuk kejujuran audit)

Beberapa POST body via curl `--data-binary @file` berhasil (endpoint nyata → 409 benar),
sedangkan beberapa pengiriman via inline `-d '{\"..\"}'` di shell audit tercatat 422
"kolom wajib diisi" — body tak sampai controller (artefak quoting load stream harness), bukan
perilaku aplikasi; dibuktikan dengan body file yang no-BOM (byte `7B 22 73 6F`) selalu benar
diterima di endpoint yang sama. Bila terulang saat verifikasi lanjutan, sarankan pakai file body
atau client UI nyata.
