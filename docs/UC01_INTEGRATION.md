# Integrasi UC01 — Week 10

Alur yang diimplementasikan: Next.js → Laravel dengan sesi cookie/CSRF/RBAC → database queue → FastAPI dengan model Colab → penyimpanan hasil dan case Pending → tampilan hasil. Tombol login sekarang memanggil API; halaman analisis membaca hasil tersimpan, termasuk setelah refresh melalui URL `?batch=<id>`.

## Komponen dan kontrak

- `apps/web`: login, logout, redirect role, unggah CSV, polling batch, retry, case dan ulasan asal. Developer diarahkan ke halaman backlog yang masih merupakan persiapan Sprint 2.
- `apps/api`: autentikasi yang sebelumnya dikerjakan tim dilanjutkan dengan middleware cookie/CSRF, CORS origin terbatas, migration tambahan, upload dan worker analisis. Penulis artefak sebelumnya tetap tercatat pada riwayat Git.
- `services/analysis`: layanan utama integrasi, menggantikan stub dengan pipeline hasil Colab di `uc01/`. Artefak model tetap privat.
- `experiments/uc01-api`: snapshot prototipe penelitian PR #9, bukan layanan yang dipakai Laravel setelah integrasi ini.

| Endpoint | Perilaku |
| --- | --- |
| GET `/api/v1/auth/csrf` | Menyiapkan sesi dan token CSRF; mutasi mengirim `X-CSRF-TOKEN` |
| POST `/api/v1/auth/login` | Login, regenerasi sesi, rate limit; remember 30 hari bila dipilih |
| GET `/api/v1/auth/me` | Identitas dan role dari sesi |
| POST `/api/v1/auth/logout` | Invalidasi sesi serta token remember |
| POST `/api/v1/analysis-batches` | Multipart `file`, opsional `Idempotency-Key`; 202 dan ID batch |
| GET `/api/v1/analysis-batches/{id}` | Status, hasil per ulasan dan case; hanya pengunggah dengan role Maintenance |
| POST `/api/v1/analysis-batches/{id}/retry` | Hanya batch Gagal; 202; batch Selesai/Diproses menghasilkan 409 |
| POST `/internal/v1/analyze` | Internal Laravel–FastAPI; request ID numerik DB dan teks; rating tidak dipakai sebagai fitur inference |

CSV UTF-8 wajib memiliki `reviewId`, `Review Teks`, `rating`, `date`; tanggal `YYYY-MM-DD HH:MM:SS`. Kolom lain boleh tersedia. Batas aplikasi: 8 MiB/10.000 ulasan. ID ganda, rating/tanggal salah, teks kosong, atau kolom tidak sesuai menolak seluruh unggahan dengan 422. Teks berkoma, kutip dan newline didukung. Parser Laravel bersifat ketat; parser karantina pada eksperimen lama tidak dipakai untuk jalur aplikasi.

Adapter mempertahankan `kategori/sentimen/modul/fitur` pada kontrak kelompok, dengan nilai sentimen `positive/negative/neutral`. Field penelitian seperti `needs_manual_review` tetap disimpan. Skor penelitian 0–100 dinormalisasi menjadi **0–10** (`score_internal = score_research / 10`) agar sesuai kontrak kelompok; UI menyebut denominator /10. Aturan severity/category/grouping/rekomendasi masih berstatus draft. Kandidat case selalu Pending, belum Valid dan belum menjadi backlog.

## Persistensi dan kegagalan

Migration baru bersifat tambahan: remember token; request key/hash pada batch; JSON hasil pada ulasan; serta batch/group key pada case. Entitas sumber tetap dipertahankan. Tidak ada migration lama yang ditulis ulang.

Upload menyimpan batch, ulasan dan job database dalam transaksi. Key yang sama dengan hash berbeda ditolak 409. Key yang sama dengan hash sama mengembalikan batch sebelumnya. Tanpa key, hash file digunakan sebagai key per pengunggah; UI memakai UUID per pemilihan file. Job memeriksa kepemilikan ID ulasan dan seluruh member case sebelum menyimpan hasil secara atomik. Job yang dipanggil ulang setelah Selesai tidak membentuk case baru. Error layanan/output mengubah batch menjadi Gagal dengan pesan aman; retry memakai ulasan yang sudah tersimpan.

Gunakan database queue, timeout HTTP 120 detik, timeout worker 180 detik dan `retry_after` 240 detik. Uji mati layanan dan retry normal sudah dilakukan; crash keras mesin/worker dan uji konkurensi banyak worker belum menjadi bukti penerimaan. FastAPI dijalankan hanya pada loopback/private network; frontend mengakses Laravel.

## Bukti 19 Oktober 2026

- [31 tes Laravel lulus](evidence/uc01-integration/laravel_mariadb_tests.json), memakai database uji terpisah MariaDB 10.4.32 melalui driver mysql, PHP 8.4.26. Ini bukan verifikasi pada Oracle MySQL 8.
- [Ringkasan pemeriksaan](evidence/uc01-integration/validation.json): layanan analisis model asli, typecheck/build frontend, dan smoke HTTP.
- [Pemulihan layanan nyata](evidence/uc01-integration/live_recovery.json): Gagal tanpa output parsial → layanan hidup → retry Selesai; 3 ulasan/2 case; retry batch sukses ditolak.
- [Tampilan hasil](evidence/uc01-integration/browser-persisted-cases.png): browser login, unggah fixture tersamar, hasil Selesai, refresh mempertahankan case dan ulasan asal.

Tes Laravel memakai fake HTTP untuk variasi kegagalan kontrak. Smoke HTTP dan demo browser memakai layanan/model asli serta database persisten. Seluruhnya memakai fixture tersamar, bukan dataset mentah. Training notebook tidak diulang pada perubahan integrasi ini; metrik tetap berasal dari run Colab sebelumnya.

## Batas status

Implementasi terintegrasi ini siap untuk review/demo lokal, belum otomatis Done. Sampel berlabel manusia, persetujuan aturan domain, pengujian beban/full dataset melalui aplikasi, peninjauan artefak SRS, dan penerimaan kelompok/PO masih perlu ditutup. Prediksi contoh “Aplikasi tidak lambat dan bagus” masih dapat menjadi negative; keberhasilan integrasi tidak membuktikan seluruh prediksi sudah benar. Skor model sebelumnya memakai proksi rating, bukan gold label manusia.

Panduan instalasi ada di [SETUP_GUIDE.md](SETUP_GUIDE.md). Model, `.env`, database, cookie, dan rahasia tidak masuk Git.
