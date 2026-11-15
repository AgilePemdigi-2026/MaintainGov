# LAPORAN IMPLEMENTASI MINGGU 14 — INTEGRASI GITHUB UC04–UC06 & PERSIAPAN STAGING

**Project:** MaintainGov  
**Sprint:** Sprint 3 – Week 14  
**PIC:** Muhammad Hanif (`@Haniippp`)  
**Backend Reviewer:** Demas Axel Marfiansyah  
**Frontend Support:** Valisha  
**E2E Support:** Gerard  
**Klasifikasi Effort:** Carry-over work (0 additional SP)  
**Tanggal:** 15 November 2026  

---

## A. Ringkasan Implementasi (Implementation Summary)

Selama Sprint 3 Week 14, dilakukan penyelesaian menyeluruh terhadap integrasi GitHub untuk **UC04 (Mengerjakan Maintenance Issue)**, **UC05 (Mengajukan Pull Request)**, serta implementasi lengkap **UC06 (Review & Sinkronisasi Pull Request)**.

Arsitektur aplikasi yang ada dipertahankan secara utuh tanpa penulisan ulang yang tidak perlu:
- **Backend Laravel:** Memanfaatkan `GitHubService` sebagai adapter terpusat, `PullRequestService` untuk UC05, dan membangun `PullRequestReviewService` (871 baris) untuk UC06.
- **Frontend Next.js:** Menambahkan workspace `/pull-requests` (halaman daftar dan detail review PR), form keputusan review untuk Tim Maintenance, role-gating, dan integrasi tombol sinkronisasi.
- **Database & Model:** Menambahkan migrasi `2026_11_13_170513_add_uc06_review_and_merge_sync` yang mencakup perluasan tabel `pull_request`, pembuatan tabel `code_review`, serta penambahan enum status `'Ready for Merge'` pada `maintenance_backlog`.
- **Webhook:** Mengintegrasikan penanganan event `pull_request` dan `pull_request_review` dengan rekonsiliasi otoritatif langsung ke GitHub API untuk mencegah *out-of-order state*.

---

## B. Status Penggunaan (UC04–UC06 Status)

| Use Case | SP | Status | Catatan Verifikasi |
|---|---|---|---|
| **UC04 — Mengerjakan Maintenance Issue** | 4 SP | **COMPLETE** | Klaim atomik berhasil, locking bekerja, pembuatan branch otomatis best-effort terimplementasi dengan tombol fallback, verifikasi push manual dan push webhook berjalan dengan validasi SHA GitHub nyata. |
| **UC05 — Mengajukan Pull Request** | 5 SP | **COMPLETE** | Pre-flight validasi branch target dan commit SHA lulus, integrasi `POST /pulls` GitHub nyata, penyimpanan metadata PR terverifikasi, idempotensi berbasis fingerprint dan recovery rekonsiliasi bebas duplikasi. |
| **UC06 — Review & Sinkronisasi Pull Request** | Sprint 3 Scope | **COMPLETE** | Daftar & detail PR per role, pengiriman review nyata (`APPROVE` / `REQUEST_CHANGES` dengan catatan wajib), pencegahan self-review, sinkronisasi dua arah via endpoint `/sync` dan webhook, transisi status backlog ke `Ready for Merge`, `Sedang Dikerjakan`, atau `Selesai` jika merged. |

---

## C. Status Hak Akses & Kredensial GitHub (GitHub Permission Status)

- **Mekanisme Autentikasi:** Fine-grained Personal Access Token (PAT) server-side via `GITHUB_TOKEN`.
- **Status Read Path:** **VERIFIED PASS** (`php artisan github:check` berhasil membaca metadata repositori `AgilePemdigi-2026/MaintainGov` dengan HTTP 200).
- **Status Write Path:** **BLOCKED OLEH IZIN TOKEN GITHUB**
  - Pembuatan branch otomatis saat klaim menghasilkan HTTP 403 `Resource not accessible by personal access token` karena token yang terpasang saat ini baru memiliki izin `Contents: Read-only`.
  - Token membutuhkan pembaruan oleh pemilik token di GitHub Settings menjadi:
    1. **`Contents: Read and write`** (pembuatan branch ref)
    2. **`Pull requests: Read and write`** (pembuatan PR & submit review)
    3. **`Metadata: Read-only`** (mandatory)
- **Zero-Token Exposure:** Tidak ada token atau secret yang dicetak pada log, respon API, commit, atau dokumentasi.

---

## D. Perubahan Basis Data (Database Changes)

Migrasi baru: `database/migrations/2026_11_13_170513_add_uc06_review_and_merge_sync.php`
1. **Perubahan Tabel `pull_request`:**
   - Kolom baru: `author_login` (varchar 100), `is_draft` (boolean), `review_state` (enum: pending, approved, changes_requested), `merged_at` (timestamp), `closed_at` (timestamp), `github_created_at` (timestamp), `github_updated_at` (timestamp), `last_synced_at` (timestamp).
   - Index baru: `review_state`, `last_synced_at`.
2. **Tabel Baru `code_review`:**
   - Kolom: `code_review_id` (PK), `pull_request_id` (FK), `backlog_id` (FK), `reviewer_id` (FK nullable), `reviewer_login`, `github_review_id` (unique), `github_state`, `body`, `commit_id`, `html_url`, `source`, `request_fingerprint` (unique), `submitted_at`, `synced_at`.
3. **Perubahan Tabel `maintenance_backlog`:**
   - Memperluas enum kolom `status` menjadi: `'Ready for Development'`, `'Sedang Dikerjakan'`, `'Ready for Pull Request'`, `'Ready for Merge'`, `'Selesai'`.

---

## E. Kontrak API (API Contract Summary)

Endpoint baru yang ditambahkan pada `routes/github.php`:
- `GET /api/v1/pull-requests` — Daftar PR dengan filter status & search (Role: Tim Maintenance & Developer).
- `GET /api/v1/pull-requests/{id}` — Detail PR & riwayat review lengkap.
- `POST /api/v1/pull-requests/{id}/sync` — Sinkronisasi otoritatif snapshot dari GitHub.
- `POST /api/v1/pull-requests/{id}/reviews` — Submit keputusan review `APPROVE` / `REQUEST_CHANGES` (Role: Tim Maintenance).

---

## F. Hasil Pengujian Otomatis (Testing Results)

### 1. Backend PHPUnit Suite
- **Total Test:** 276 tests
- **Passed:** 275 tests (0 failure)
- **Skipped:** 1 test (`SchemaSprint1Test.php:113` — sengaja dilewati karena SQLite test runner tidak mengevaluasi CHECK constraint spesifik MySQL)
- **Total Assertions:** 1,381 assertions
- **Cakupan Pengujian Baru UC06:** 14 test di `PullRequestReviewTest` (lulus 100%), 14 test di `GitHubWebhookTest` (lulus 100%).

### 2. Frontend Playwright E2E Suite
- **Total Test (Sprint 2/3 Workspace Specs):** 36 tests
- **Passed:** 36 tests (0 failure, durasi 39.6 detik)
  - `sprint2-pull-request-review.spec.ts`: 8 passed
  - `sprint2-issue-pr.spec.ts`: 9 passed
  - `sprint2-case.spec.ts`: 9 passed
  - `sprint2-backlog.spec.ts`: 10 passed
- **TypeScript Check:** `tsc --noEmit` lulus dengan 0 kesalahan (0 error).

---

## G. Bukti Live GitHub (Live GitHub Evidence)

1. **Live Read Metadata (`github:check`):**
   - Status: `Connected (200 OK)`
   - Repository: `AgilePemdigi-2026/MaintainGov`
   - Default Branch: `main`
   - Permissions: `admin: true, maintain: true, push: true, triage: true, pull: true`
2. **Live Push Attribute Check (`github:smoke-uc05`):**
   - Push permission reported: `granted`
3. **Live Write Operations:**
   - Terkendala HTTP 403 saat membuat branch git ref karena fine-grained PAT kekurangan cakupan izin tulis `Contents: Read and write`. Tidak ada data palsu yang direkayasa.

---

## H. Kesiapan Lingkungan Staging (Staging Readiness)

- Status Kesiapan: **PREPARED & CONFIGURED**
  - Runbook lengkap tersedia di `docs/STAGING_DEPLOYMENT_RUNBOOK.md`.
  - Spesifikasi `.env` staging telah disiapkan dan dibersihkan dari kredensial hardcoded.
  - Template konfigurasi Nginx, Supervisor, dan Queue Worker telah dirinci.
- Status Akses Infrastruktur Fisik: **PENDING INFRASTRUCTURE PROVISIONING** (Menunggu alokasi VM / server hosting staging pada Week 15).

---

## I. Hambatan yang Tersisa (Remaining Blockers)

1. **Pembaruan Izin Fine-grained PAT:** Membutuhkan tindakan pemilik token di GitHub Settings untuk mengubah izin `Contents` menjadi `Read and write` dan memastikan `Pull requests` adalah `Read and write`.
2. **Koneksi WARP Proxy Lokal:** PHP cURL pada mesin Windows lokal gagal melakukan DNS lookup jika Cloudflare WARP aktif (`Could not resolve host: api.github.com`). Solusi: Jalankan `warp-cli disconnect` sebelum mengakses API GitHub dari CLI lokal.

---

## J. Catatan Handoff Tim (Handoff Notes)

### Untuk Demas Axel Marfiansyah (Backend Reviewer)
- **Arsitektur UC06:** Dikelola oleh `PullRequestReviewService` (871 baris). Alur review menerapkan pola transaksi atomik, lock concurrency berbasis cache key `uc06-review:{pr_id}:{user_id}`, dan idempotensi sidik jari SHA-256.
- **Out-of-Order Webhook Safety:** Pada webhook `pull_request` dan `pull_request_review`, backend tidak langsung mempercayai body payload, melainkan memanggil ulang `getPullRequest()` dan `listPullRequestReviews()` untuk mengambil state resmi dari GitHub.
- **Review Self-Forbidden & Matching Identity:** Reviewer dicegah mereview PR milik sendiri. Akun reviewer wajib memiliki `github_login` yang sama dengan akun pemilik token server saat menggunakan model token tunggal.

### Untuk Valisha (Frontend Support)
- **Workspace Baru `/pull-requests`:** Telah tersedia route Next.js pada `apps/web/src/app/(workspace)/pull-requests/` (daftar) dan `[id]/page.tsx` (detail & review).
- **Format Review Form:** Radio button pilihan `Approve` vs `Request changes`. Jika memilih `Request changes`, field catatan wajib diisi dan divalidasi di sisi client sebelum mengirim POST.
- **Handling Identity Mismatch:** Jika backend mengembalikan kode error `GITHUB_REVIEWER_IDENTITY_MISMATCH` (HTTP 403), UI akan menampilkan pesan peringatan yang ramah dan menonaktifkan tombol submit review.

### Untuk Gerard (BDD / E2E Support)
- **E2E Suite UC06:** 8 skenario pengujian Playwright telah dibuat dan lulus di `apps/web/tests/e2e/sprint2-pull-request-review.spec.ts`.
- **Mocking Boundaries:** Seluruh pengujian E2E menggunakan helper `mockApi` untuk menyimulasikan respon backend secara deterministik tanpa melakukan request jaringan langsung ke GitHub selama CI.
- **Skenario Live Smoke:** File `apps/web/tests/e2e/sprint2-live.spec.ts` disiapkan untuk pengujian browser nyata jika variabel `MAINTAINGOV_SPRINT2_LIVE=1` diaktifkan pada database terisolasi.
