# Bukti Penyelesaian Tugas W10-05

## Informasi Tugas
- **ID Tugas**: W10-05
- **Judul**: Buat API login, logout, dan pembatasan akses (RBAC)
- **Sprint**: Sprint 1, Week 10
- **Penanggung Jawab**: Demas
- **Reviewer**: Hanif

## Daftar Artefak yang Dihasilkan
| No | Artefak | Lokasi File |
|----|---------|-------------|
| 1 | Model Pengguna | `app/Models/Pengguna.php` |
| 2 | Auth Controller | `app/Http/Controllers/AuthController.php` |
| 3 | Role Middleware | `app/Http/Middleware/RoleMiddleware.php` |
| 4 | API Routes | `routes/api.php` |
| 5 | Konfigurasi Auth & Middleware | `config/auth.php`, `bootstrap/app.php` |
| 6 | Feature Test | `tests/Feature/AuthTest.php` |
| 7 | Kontrak Respons API | `docs/artifacts/shared/auth-response-contract.md` |

## Hasil Pengujian Otomatis (Feature Test)
Semua skenario pengujian berhasil dijalankan dengan perintah `php artisan test --filter=AuthTest`:
- ✅ login berhasil dengan credential valid
- ✅ login ditolak jika field kosong
- ✅ login ditolak dengan email salah
- ✅ login ditolak dengan password salah
- ✅ me mengembalikan identitas
- ✅ me ditolak tanpa sesi
- ✅ logout berhasil
- ✅ sesi tidak aktif setelah logout
- ✅ developer ditolak akses tim maintenance (RBAC 403)
- ✅ tim maintenance berhasil akses dashboard

**Status:** 10 Passed, 0 Failed.

## Hasil Pengujian Manual (REST Client)
- ✅ Login sebagai `Tim Maintenance` berhasil (Status 200, `success: true`).
- ✅ Login sebagai `Developer` berhasil (Status 200, `success: true`).
- ✅ Mengakses `/me` setelah login berhasil mengembalikan data user.
- ✅ Mengakses `/tim-maintenance/dashboard` sebagai Developer **ditolak** (Status 403, `success: false`).
- ✅ Logout berhasil menghapus sesi.

## Catatan Teknis & Penyesuaian
1. **Integrasi W10-04:** Model `Pengguna` disesuaikan sepenuhnya dengan skema database Sprint 1 (Tabel: `pengguna_sistem`, Primary Key: `user_id`).
2. **Session di API:** Middleware `StartSession` ditambahkan secara manual ke grup `api` di `bootstrap/app.php` agar autentikasi berbasis cookie dapat berfungsi di endpoint API.
3. **Kompatibilitas Testing SQLite:** `DB::statement` untuk CHECK constraint pada migrasi dibungkus dengan kondisi `if (DB::getDriverName() !== 'sqlite')` agar pengujian *in-memory* tidak mengalami error syntax.