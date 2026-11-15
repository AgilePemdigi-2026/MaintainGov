# LAPORAN LIVE GITHUB SMOKE TEST (UC04–UC06)

**Project:** MaintainGov  
**Sprint:** Sprint 3 – Week 14  
**PIC:** Muhammad Hanif (`@Haniippp`)  
**Target Repository:** `AgilePemdigi-2026/MaintainGov`  
**Execution Timestamp:** 15 November 2026  

---

## 1. Aturan Pengujian Live

Sesuai aturan eksekusi proyek (Execution Rules §12 & Phase 9):
1. **Dilarang memalsukan data:** URL Pull Request, nomor PR, commit SHA, dan keputusan review GitHub nyata tidak boleh direkayasa.
2. **Perlindungan Repositori Produksi:** Pengujian otomatis (`phpunit`) **tidak pernah** membuat Pull Request atau branch nyata pada repositori `AgilePemdigi-2026/MaintainGov` untuk mencegah spam dan modifikasi data tanpa izin. Seluruh alur tulis otomatis diuji menggunakan HTTP Mocking (`Http::fake`).
3. **Pemisahan Jalur Baca vs Jalur Tulis:** Operasi baca diuji secara *live* terhadap GitHub API; operasi tulis *live* hanya dijalankan jika token memiliki izin eksplisit dan dilakukan pada branch/repositori uji.

---

## 2. Bukti Eksekusi Live Read Path (Artisan github:check & smoke-uc05)

### 2.1 Verifikasi Koneksi Repositori (`github:check`)
Perintah dijalankan langsung pada environment server lokal:
```powershell
php artisan github:check
```

**Output Terminal Nyata:**
```text
PASS: GitHub repository metadata was read successfully.
Authentication: server-side Bearer token (value hidden).
+----------------+----------------------------------------------------------------------+
| Field          | Value                                                                |
+----------------+----------------------------------------------------------------------+
| owner          | AgilePemdigi-2026                                                    |
| name           | MaintainGov                                                          |
| full_name      | AgilePemdigi-2026/MaintainGov                                        |
| default_branch | main                                                                 |
| private        | no                                                                   |
| html_url       | https://github.com/AgilePemdigi-2026/MaintainGov                     |
| permissions    | {"admin":true,"maintain":true,"push":true,"triage":true,"pull":true} |
+----------------+----------------------------------------------------------------------+
```
*Hasil:* **PASS (200 OK)** — Metadata repositori berhasil diakses, repositori terverifikasi publik dan default branch `main`.

---

### 2.2 Verifikasi Read Smoke UC05 (`github:smoke-uc05 --require-write`)
Perintah dijalankan langsung pada environment server lokal:
```powershell
php artisan github:smoke-uc05 --require-write
```

**Output Terminal Nyata:**
```text
PASS: koneksi read-only GitHub berhasil.
+-----------------+-------------------------------+
| Field           | Value                         |
+-----------------+-------------------------------+
| owner           | AgilePemdigi-2026             |
| name            | MaintainGov                   |
| full_name       | AgilePemdigi-2026/MaintainGov |
| default_branch  | main                          |
| private         | no                            |
| push_permission | granted                       |
+-----------------+-------------------------------+
PASS: smoke read-only selesai (tidak ada branch yang diminta).
Write path tidak dijalankan oleh smoke test ini.
```
*Hasil:* **PASS** — Token server memiliki atribut push permission yang dilaporkan oleh GitHub API.

---

## 3. Status Jalur Tulis Live (Write Path) & Evaluasi Izin

| Operasi Tulis Live | Status | Bukti / Diagnosa |
|---|---|---|
| **Pembuatan Branch Ref (`POST /git/refs`)** | **TERKENDALA IZIN (HTTP 403)** | Saat klaim issue UC04 mencoba membuat branch otomatis `maintenance/issue-8`, GitHub mengembalikan: `Resource not accessible by personal access token` (HTTP 403). Hal ini terjadi karena Fine-grained PAT yang dikonfigurasi saat ini baru memiliki izin `Contents: Read-only`. |
| **Pembuatan Pull Request (`POST /pulls`)** | **SIAP — MEMBUTUHKAN IZIN TULIS** | Kode backend `PullRequestService` lengkap dengan validasi SHA dan idempotensi. Eksekusi live membutuhkan PAT dengan izin `Pull requests: Read and write`. |
| **Pengiriman Review (`POST /pulls/{n}/reviews`)** | **SIAP — MEMBUTUHKAN IZIN TULIS & MATCHING USER** | Kode backend `PullRequestReviewService` lengkap dengan role-gating, self-review check, dan lock. Eksekusi live membutuhkan PAT dengan izin `Pull requests: Read and write` dan akun reviewer yang bersesuaian dengan pemilik token server. |

---

## 4. Bukti Riwayat Pull Request & Commit Nyata (Merged Sprint 2)

Berikut adalah bukti commit dan Pull Request nyata yang telah dibuat dan dimerge pada repositori `AgilePemdigi-2026/MaintainGov` selama pengerjaan sprint:

- **PR #39:** Implementasi Lengkap UC04–UC05 (Merged to `main`)
- **PR #40:** Hardening Skema & Database Regression (Merged to `main`)
- **PR #41:** Maintenance Issue 1 Push Verification (Merged)
- **PR #42:** Fix Sprint 2 E2E Release (Merged)
- **PR #44:** Maintenance Issue 2 (Merged)
- **PR #45:** Maintenance Issue 3 (Merged)
- **PR #46:** Maintenance Issue 4 (Merged)
- **Head Commit saat ini:** `87752c0` (`fix(auth): perbaiki login`) di branch `maintenance/issue-7`.
