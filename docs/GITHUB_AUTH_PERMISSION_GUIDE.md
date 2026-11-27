# PANDUAN AUTENTIKASI DAN HAK AKSES GITHUB (UC04–UC06)

**Project:** MaintainGov  
**Target Repository:** `AgilePemdigi-2026/MaintainGov`  
**PIC:** Muhammad Hanif (`@Haniippp`)  
**Sprint:** Sprint 3 – Week 14  
**Dokumen Referensi:** SRS §4.6–4.8, `ARCHITECTURE.md` §5  

---

## 1. Mekanisme Autentikasi

MaintainGov mengintegrasikan GitHub REST API pada layer backend (Laravel). Terdapat dua model autentikasi yang dievaluasi:

| Model | Keunggulan | Batasan | Status Implementasi |
|---|---|---|---|
| **GitHub App (Target SRS)** | Izin berbasis instalasi organisasi, rotasi token otomatis per jam, webhook terkelola, audit log granular. | Membutuhkan setup GitHub App di organisasi `AgilePemdigi-2026` dan pertukaran JWT/installation-token. | **Target Produksi / Staging Lanjutan** |
| **Fine-grained PAT (Saat ini)** | Setup langsung via environment server (`GITHUB_TOKEN`), pembatasan repositori tunggal (`MaintainGov`), granular permission per resource. | Terikat pada akun pengguna pembuat token; semua review GitHub diatribusikan ke akun pemilik token. | **Aktif untuk Local & Staging Awal** |
| **Classic PAT** | Cakupan luas tanpa batas repositori spesifik. | Terlalu permisif (risiko keamanan tinggi). | **Dilarang (Deprecated)** |

---

## 2. Matriks Izin Repositori yang Diperlukan (Minimum Privilege)

Untuk menjalankan seluruh kapabilitas UC04, UC05, dan UC06, fine-grained Personal Access Token (PAT) harus dikonfigurasi dengan izin berikut pada repositori `AgilePemdigi-2026/MaintainGov`:

| Resource Permission | Level Akses | Digunakan Oleh Operasi API | Konsekuensi Jika Tidak Ada |
|---|---|---|---|
| **Metadata** | `Read-only` *(Mandatory)* | `GET /repos/{owner}/{repo}` (Cek koneksi repositori, validasi default branch) | HTTP 404 / 401: Sistem tidak dapat memverifikasi repositori terpercaya. |
| **Contents** | `Read and write` | `GET /repos/{owner}/{repo}/branches` (Daftar branch target)<br>`GET /repos/{owner}/{repo}/branches/{branch}` (Cek branch sumber)<br>`GET /repos/{owner}/{repo}/commits/{sha}` (Verifikasi commit push)<br>`POST /repos/{owner}/{repo}/git/refs` (Pembuatan branch otomatis saat klaim issue) | HTTP 403 `Resource not accessible by personal access token` saat sistem mencoba membuat branch otomatis `maintenance/issue-{id}`. *(Klaim lokal tetap tersimpan; tombol fallback tersedia).* |
| **Pull requests** | `Read and write` | `GET /repos/{owner}/{repo}/pulls` (Daftar PR / pre-flight)<br>`GET /repos/{owner}/{repo}/pulls/{number}` (Detail PR)<br>`POST /repos/{owner}/{repo}/pulls` (Pembuatan PR UC05)<br>`GET /repos/{owner}/{repo}/pulls/{number}/reviews` (Daftar review)<br>`POST /repos/{owner}/{repo}/pulls/{number}/reviews` (Kirim Approve / Request Changes UC06) | HTTP 403 saat membuat Pull Request (UC05) atau saat mengirimkan keputusan review (UC06). |

---

## 3. Aturan Keamanan Kredensial

1. **Zero Hardcoded Secrets:** Token GitHub tidak boleh ditulis dalam kode sumber, commit Git, komentar, atau file pengujian.
2. **Server-Side Only:** Token hanya dibaca oleh Laravel dari variabel environment `GITHUB_TOKEN`. Token **tidak pernah** dikirimkan ke frontend (Next.js) atau disimpan di dalam tabel database.
3. **Log Sanitization:** Header HTTP Authorization (`Bearer ***`) dan nilai token otomatis diredaksi dalam log aplikasi dan laporan exception.
4. **Environment Isolation:** File `.env` masuk ke dalam `.gitignore`. Hanya template `.env.example` yang di-commit.
5. **Rotasi Berkala:** Apabila token tidak sengaja tereskpos dalam kanal percakapan atau log publik, token wajib segera di-revoke dan di-generate ulang melalui GitHub Developer Settings.

---

## 4. Penanganan Identitas Reviewer (UC06)

Pada arsitektur Fine-grained PAT, token server dimiliki oleh satu akun GitHub. Saat Tim Maintenance mengirim review (`APPROVE` / `REQUEST_CHANGES`):
- GitHub API akan mencatat review atas nama pemilik token tersebut.
- Untuk mencegah ketidaksesuaian identitas antara akun MaintainGov dan akun GitHub, sistem menerapkan pemeriksaan:
  ```php
  // PullRequestReviewService.php line 698
  if (strcasecmp($identity->data['login'], $reviewer->github_login) !== 0) {
      throw ApiException::forbidden(
          'Review GitHub akan tercatat atas nama pemilik token server, bukan reviewer ini. '
          .'Gunakan akun pemilik token atau konfigurasi GitHub App/user token per reviewer.',
          'GITHUB_REVIEWER_IDENTITY_MISMATCH'
      );
  }
  ```
- **Self-Review Guard:** Sistem juga memastikan bahwa reviewer bukan merupakan penulis Pull Request (`REVIEW_SELF_FORBIDDEN`), sesuai kebijakan anti-self-review GitHub.

---

## 5. Toleransi Kesalahan Jaringan dan Rate Limit

| Kondisi | Respon GitHub | Penanganan di MaintainGov |
|---|---|---|
| **401 Unauthorized** | Token tidak valid/kedaluwarsa | Mengembalikan `GITHUB_UNAUTHORIZED` (HTTP 502), meminta admin memperbarui token. HTTP 401 sengaja tidak dipakai agar frontend tidak salah menganggap sesi pengguna habis. |
| **403 Forbidden** | Kurang izin repositori | Mengembalikan `GITHUB_FORBIDDEN` (HTTP 403), merinci izin yang kurang. |
| **429 / Rate Limited** | Limit request tercapai | Membaca header `X-RateLimit-Reset` dan `Retry-After`. Mengembalikan `GITHUB_RATE_LIMITED` (HTTP 429) dengan waktu tunggu yang aman. |
| **5xx / Timeout** | Gangguan server GitHub | Menghindari blind-retry pada operasi non-idempoten (seperti `POST review`). Sistem melakukan rekonsiliasi membaca status terlebih dahulu sebelum mengulang. |

---

## 6. Prosedur Diagnosis dan Intervensi Administrator

Jika muncul error: `Token GitHub tidak memiliki izin untuk membuat branch (HTTP 403)`:

1. Buka **GitHub** -> **Settings** -> **Developer Settings** -> **Personal Access Tokens** -> **Fine-grained tokens**.
2. Pilih token yang digunakan (misal: `MaintainGov-Local-E2E`).
3. Periksa bagian **Repository access**: Pastikan `Only select repositories` mencakup `AgilePemdigi-2026/MaintainGov`.
4. Periksa bagian **Repository permissions**:
   - `Contents`: Ubah dari `Access: Read-only` menjadi **`Access: Read and write`**.
   - `Pull requests`: Pastikan **`Access: Read and write`**.
   - `Metadata`: Pastikan **`Access: Read-only`**.
5. Simpan perubahan (**Save changes**).
6. Verifikasi kembali di terminal:
   ```powershell
   php artisan github:check
   php artisan github:smoke-uc05 --require-write
   ```
