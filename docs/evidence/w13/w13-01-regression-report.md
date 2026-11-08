# W13-01 — Laporan Database Hardening, Regression & Audit UC02–UC03

Tanggal: 2026-11-08 | Eksekusi: sesi agen (atas nama Demas, owner backend) | Reviewer: belum
Scope: review skema, verifikasi konsistensi status & integritas, matriks skenario UC02/UC03, regression sqlite + MySQL, perbaikan defect, dokumentasi. **Bukan penambahan fitur baru.**

> Catatan konteks: `docs/artifacts/shared/W12-PROGRESS-HANDOFF.md` yang dijadwalkan dibaca pada inisiasi issue **tidak ditemukan di repository** (tidak pernah dikommit atau sudah dihapus). Konteks diambil dari `pdm-delta-sprint2.md`, `decision-log.md`, dan kontrak `uc02-api.md` / `uc03-api.md`.

---

## 1. Tabel review skema & constraint

| # | Objek | Status | Catatan (file:baris) |
| --- | --- | --- | --- |
| 1 | FK `maintenance_backlog.case_id` → `maintenance_case` ON DELETE RESTRICT | **OK** | `2026_11_01_000001_create_maintenance_backlog_table.php:30-32`; denyut probe langsung SQL ditolak (error 1452, lihat §2 N1) |
| 2 | UNIQUE `maintenance_backlog.case_id` | **OK** | ibid:27 (`maintenance_backlog_case_unique`); menjamin BR-10 tepat satu backlog per case — ditegaskan ulang oleh API test dan probe A2/A3 |
| 3 | FK `maintenance_backlog.assigned_to` → `pengguna_sistem` ON DELETE SET NULL, nullable | **OK** | ibid:14 & 34-36 |
| 4 | FK `ulasan.batch_id` → `analysis_batch` RESTRICT (+ composite UNIQUE batch/source sebagai index prefix) | **OK** | `2026_10_18_015815_create_ulasan_table.php:24,27-29` |
| 5 | FK `ulasan.case_id` → `maintenance_case` RESTRICT, nullable + index | **OK** | ibid:15,25,30-32 |
| 6 | Index `maintenance_case.status_validasi` | **OK** | `create_maintenance_case_table.php:26` + idempoten `2026_11_01_000002_add_uc03_index_hardening.php:15-17` |
| 7 | Index `maintenance_backlog.status` | **OK** | `create_maintenance_backlog_table.php:28` + hardening idempoten:18-20 |
| 8 | Index `ulasan.case_id` | **OK** | `create_ulasan_table.php:25` + hardening idempoten:21-23 |
| 9 | ENUM `maintenance_case.status_validasi` = Pending/Valid/Tidak Valid | **OK** | `create_maintenance_case_table.php:22` — sesuai SRS |
| 10 | ENUM `maintenance_backlog.status` = 4 nilai SRS (`Selesai`, **bukan** `Ready for Merge`) | **OK** | `create_maintenance_backlog_table.php:17-22`; `Ready for Merge` hanya untuk tabel `pull_request` (pdm-delta-sprint2 §Aturan status). Probe langsung SQL dengan `Ready for Merge` ditolak schema (error 1265, §2 N2) |
| 11 | Kolom validator `maintenance_case.user_id` + `validated_at` | **OK** | `create_maintenance_case_table.php:13,23`; nullable sesuai pdm-delta-sprint1 (Pending belum divalidasi) |
| 12 | Timestamps `updated_at` | **OK (dengan catatan)** | `maintenance_backlog.updated_at` **ada** (`create_maintenance_backlog_table.php:24`) dan WAJIB per delta — dipakai UC03 sebagai penafsir "backlog terakhir ditinjau", selalu ditulis kontroller PATCH (`MaintenanceBacklogController.php:150`). `maintenance_case` memang **tidak memiliki** `updated_at` sejak Sprint 1 sesuai PDM (Gambar 6.3) dan kontrak UC03 tidak mengharapkannya — **tidak dianggap defect, tidak ada perubahan skema** |
| 13 | Constraint idempotensi UC01: `analysis_batch.request_key` UNIQUE(user_id, request_key) | **OK** | `2026_10_19_000001_add_uc01_integration_fields.php:10-12`; `source_sha256` kolom biasa 64 — deteksi file-bedasama-key diberlakukan di lapisan aplikasi (D-W11-02: 409), bukan constraint DB, sesuai keputusan |
| 14 | CHECK `ulasan.rating` BETWEEN 1..5 | **OK (kondisional)** | `create_ulasan_table.php:36-38` — hanya mysql/mariadb; pada sqlite diverifikasi lewat skip eksplisit `SchemaSprint1Test::test_rating_di_luar_rentang_ditolak` (lulus saat run MySQL) |

**Kesimpulan: tidak diperlukan migration baru; tidak ada perubahan skema → sezione delta PDM tidak ditulis** (aturan "tulis delta SEBELUM migration baru" tidak terpicu). Migration lama tidak disentuh.

---

## 2. Pemeriksaan integritas (MySQL `maintaingov_w13`, query + hasil)

Log lengkap: `docs/evidence/w13/mysql-integrity-w13-01.log`. Data probe telah dibersihkan setelah pemeriksaan.

| ID | Check | Query inti | Hasil |
| --- | --- | --- | --- |
| A1 | Tidak ada backlog yatim | `COUNT(*) FROM maintenance_backlog mb LEFT JOIN maintenance_case mc … WHERE mc.case_id IS NULL` | **0** — OK |
| A2 | Tidak ada case dengan >1 backlog | `GROUP BY case_id HAVING COUNT(*) > 1` | **0 baris** — OK |
| A3 | 'Valid' ⇔ tepat 1 backlog; 'Tidak Valid' ⇔ 0 backlog; Pending ⇔ 0 backlog | `LEFT JOIN backlog GROUP BY mc.case_id, status_validasi` | case 101 Valid=**1**, 102 Tidak Valid=**0**, 103 Pending=**0** — OK |
| A4 | Tidak ada backlog berstatus di luar enum | `GROUP BY status` | hanya `Ready for Development`; luar enum dibuktikan ditolak schema (N2) |
| A5 | Distribusi status_validasi enum | `GROUP BY status_validasi` | Pending/Valid/Tidak Valid — OK |
| A6 | Case non-Pending selalu punya validator + waktu | `status_validasi<>'Pending' AND (user_id IS NULL OR validated_at IS NULL)` | **0** — OK |
| A7 | Backlog hanya pada case Valid | `JOIN … WHERE mc.status_validasi <> 'Valid'` | **0** — OK |
| A8 | Tidak ada ulasan yatim case | `LEFT JOIN … IS NOT NULL AND mc IS NULL` | **0** — OK |
| A9 | Index/unique hardening terpasang | `information_schema.STATISTICS` | semua 5 terdeteksi (2 unique + 3 index) |
| N1 | Probe FK RESTRICT: insert backlog case 999999 | langsung SQL | **ditolak error 1452** (`ON DELETE RESTRICT`) |
| N2 | Probe enum: insert status `Ready for Merge` | langsung SQL | **ditolak error 1265** (Data truncated) |

Catatan: invarian A3/A6/A7 bukan ditegakkan CHECK constraint DB tetapi (a) constraint UNIQUE/FK dan (b) alur API pada `MaintenanceCaseController::validate` (transaksi + lockForUpdate, tulis validator & backlog atomik); keduanya diverifikasi per feature test §3 tes T1–T7.

---

## 3. Matriks skenario UC02 & UC03

Semua respons error ber-envelope `{success, message, data}` Bahasa Indonesia. Test suite: sqlite (phpunit.xml) & MySQL hasil identik.

### UC02 — Peninjauan & Validasi Case

| Skenario | Ekspektasi | Test | Status |
| --- | --- | --- | --- |
| Ya sukses → case Valid, validator+waktu, tepat 1 backlog Ready for Development | 200 + payload backlog_id | `MaintenanceCaseValidationTest::test_keputusan_ya_membentuk_lima_kolom_wajib_dan_satu_backlog` | ✅ |
| Tidak sukses → Tidak Valid, 0 backlog | 200, backlog_id null | `test_keputusan_tidak_mengubah_status_tanpa_backlog` | ✅ |
| Validasi ulang Valid→Ya / Valid→Tidak | 409, tanpa backlog kedua | `test_keputusan_ya_pada_case_sudah_valid_ditolak_409` | ✅ |
| Validasi ulang Tidak Valid→Tidak / **→Ya (baru)** | 409, tanpa backlog | `test_keputusan_tidak_pada_case_sudah_tidak_valid_ditolak_409` (assertion baru) | ✅ |
| Anonim (semua 3 endpoint) | 401, state tetap Pending | `test_anonim_ditolak_401` | ✅ |
| Developer (GET list/detail/POST validate) | 403, state tidak berubah | `test_developer_ditolak_403` | ✅ |
| **POST validate id asing (baru)** | 404 envelope, tanpa partial write | `test_validate_case_tidak_ditemukan_404_tanpa_partial_write` | ✅ |
| GET detail id asing | 404 | `test_detail_case_tidak_ditemukan` | ✅ |
| Body rusak: `keputusan` tidak sah / kosong | 422 + `errors.keputusan` Bahasa Indonesia, tanpa write | `test_keputusan_di_luar_ya_tidak_ditolak_422` (detail errors baru) | ✅ |
| Validasi ganda berurutan | 409 tanpa backlog kedua | `test_keputusan_ya_pada_case_sudah_valid_ditolak_409` (count=1) | ✅ |
| Kegagalan di tengah transaksi (konflik UNIQUE backlog proses lain) | 409, rollback penuh: case kembali Pending, validator kosong | `test_konflik_unique_mengembalikan_409_dan_mengembalikan_case_ke_pending` | ✅ |
| Input tidak valid 422 | tidak ada partial write | `test_keputusan_di_luar_ya_tidak_ditolak_422` (status masih Pending) | ✅ |
| List default Pending / filter / per_page>100 | 200 total 1 / 422 | `test_list_*` (3 test) | ✅ |
| **`?status=` kosong (baru)** | fallback default Pending, 200 — sebelumnya 422 | `test_list_status_kosong_menggunakan_default_pending` | ✅ (fix defect #1) |

### UC03 — Maintenance Backlog

| Skenario | Ekspektasi | Test | Status |
| --- | --- | --- | --- |
| PATCH sukses → field case berubah + bucket priority + `updated_at` backlog | 200 payload | `MaintenanceBacklogTest::test_patch_sukses_mengubah_field_dan_updated_at_backlog` | ✅ |
| **PATCH lalu GET membaca nilai yang sama (baru)** | write-read identik, termasuk `updated_at` | `test_patch_lalu_get_membaca_kembali_nilai_sama` | ✅ (lewat D-W12-02-01: Medium→5.50) |
| `updated_at` backlog berubah saat PATCH | timestamps | `test_patch_sukses_mengubah_field_dan_updated_at_backlog` | ✅ |
| 409 BR-12 saat status ≠ Ready for Development | 409, field case tidak berubah | `test_patch_status_sudah_dikerjakan_ditolak_409_br12` | ✅ |
| 422 field wajib (`ringkasan_masalah`) / `priority` tidak sah | 422 + `errors` Bahasa Indonesia | `test_patch_ringkasan_masalah_wajib_dan_priority_terbatas` | ✅ |
| PATCH id asing | 404 | `test_patch_backlog_tidak_ditemukan_404` | ✅ |
| GET id asing | 404 | `test_detail_tidak_ditemukan_404_bahasa_indonesia` | ✅ |
| Anonim (GET list/detail, PATCH) | 401 ×3 | `test_anonim_ditolak_401_pada_ketiga_endpoint` | ✅ |
| Developer: GET boleh, PATCH 403 | sesuai role | `test_developer_dapat_membaca_tetapi_tidak_dapat_mengubah_backlog` | ✅ |
| **`?status=` kosong (baru)** | menampilkan semua status, 200 — sebelumnya 422 | `test_list_status_kosong_menampilkan_semua_backlog` | ✅ (fix defect #1) |
| Urutan list deterministik High>Medium>Low→skor→id | 200 urutan | `test_list_urutan_prioritas_high_medium_low_lalu_backlog_id` | ✅ |
| Filter status `?status=Selesai` / tidak sah | 200/422 | `test_list_filter_status_opsional_tervalidasi` | ✅ |

Global exception (baseline perilaku, dipertahankan): `GlobalExceptionHandlerTest` 4 tes — 404 router envelope Indonesia, bukan-JSON tidak ber-envelope, SQLSTATE tanpa bocor detail, dsb. ✅

---

## 4. Regression

| Suite | Sebelum (baseline) | Sesudah | Merk -detail |
| --- | --- | --- | --- |
| SQLite (`php artisan test`) | 233 tes, 232 pass, 1 skip (CHECK rating → MySQL), 1.157 asersi | **237 tes, 236 pass, 0 gagal, 1 skip (by design), 1.180 asersi** | +4 test baru |
| MySQL `maintaingov_w13` | — | **237 tes, 237 pass, 1.181 asersi, 0 skipped** | CHECK rating ikut diuji |

Log MySQL: `docs/evidence/w13/mysql-migrate-seed-w13-01.log` (migrate:fresh --seed) dan `docs/evidence/w13/mysql-test-run-w13-01.log` (`php artisan test`, DB_CONNECTION=mysql).

## 5. Verifikasi patch lama utuh (regression guard)

| Patch | Lokasi verifikasi | Status |
| --- | --- | --- |
| Rotasi remember_token saat logout | `AuthController.php:63-73`; test `AuthTest::test_logout` | ✅ utuh |
| Aturan byte eksak 10.000.000 | `AnalysisController.php:23-24`; test `AnalysisFlowTest:192-220` | ✅ utuh |
| CSRF/session middleware group `api` | `bootstrap/app.php:30-41` (EncryptCookies, StartSession, PreventRequestForgery + pengecualian webhook HMAC) | ✅ utuh |
| CORS memuat PUT/PATCH/DELETE | `config/cors.php:4` + `allowed_headers` X-CSRF-TOKEN | ✅ utuh |
| Global exception handler 404/SQLSTATE ber-envelope Indonesia | `bootstrap/app.php:43-115`; test `GlobalExceptionHandlerTest` | ✅ utuh |

## 6. Defect ditemukan & diperbaiki

| # | Severity | Lokasi | Defect | Perbaikan | Test penjaga |
| --- | --- | --- | --- | --- | --- |
| 1 | Minor (kontrak) | `MaintenanceCaseController.php:15,27-29` | `?status=` kosong → 422, padahal kontrak default `Pending` (frontend dengan select kosong terdampak) | rule `sometimes|nullable|in:…` + `filled()` guard dengan fallback `Pending` | `test_list_status_kosong_menggunakan_default_pending` |
| 2 | Minor (kontrak) | `MaintenanceBacklogController.php:24` | `?status=` kosong → 422, padahal param opsional berarti semua status | rule `sometimes|nullable|in:…` (guard `filled()` sudah benar) | `test_list_status_kosong_menampilkan_semua_backlog` |
| 3 | Coverage gap | `MaintenanceCaseValidationTest.php` | POST validate id asing tidak tercover 404 | — (test baru) | `test_validate_case_tidak_ditemukan_404_tanpa_partial_write` |
| 4 | Coverage gap | `MaintenanceBacklogTest.php` | Tidak ada verifikasi write-read PATCH→GET | — (test baru) | `test_patch_lalu_get_membaca_kembali_nilai_sama` |
| 5 | Coverage gap | `MaintenanceCaseValidationTest.php` | Transisi terbalik Tidak Valid→Ya tidak dirundung | assertion tambahan pada test 409 | `test_keputusan_tidak_pada_case_sudah_tidak_valid_ditolak_409` |

Semua output perbaikan tetap ber-envelope; tidak ada perubahan skema, tidak ada perubahan kontrak.

## 7. Rujukan delta & keputusan

- **Perubahan skema: TIDAK ADA** → tidak dibuat sezione delta PDM baru; migration lama tidak disentuh sesuai aturan issue.
- **D-W12-02-01** (priority bucket High=9.00/Medium=5.50/Low=2.00 menimpa skor model): **status tetap MENUNGGU PO — tidak diubah** (interim Opsi B + `TODO(decision D-W12-02-01)` di kode; bucket ditutup test PATCH = write-read verify).
- **D-W11-01/D-W11-02**: tidak disentuh.
- **Keputusan baru: tidak ada** → tidak ada entri baru di `decision-log.md`.
- `.env.example` tidak disentuh; tidak ada commit dalam sesi ini.

## 8. Batas & keterbukaan

- 1 tes tetap skip di sqlite (`test_rating_di_luar_rentang_ditolak`) — CHECK constraint hanya ada pada MySQL; run MySQL 0 skipped.
- PDM `maintenance_case` tanpa `updated_at` adalah rancangan SRS/Gambar 6.3; bila PO kelak menginginkan audit perubahan pada case, jalankan delta baru (dilarang edit migration lama).
- MySQL yang dipakai: 8.4.3 (Laragon, `C:\laragon\data\mysql-8.4`); MariaDB 10.4 (baik latih repo sebelumnya) **belum dijalankan ulang** pada sesi ini.
