# Handoff Database — Koneksi GitHub dan UC04–UC05

Untuk: **Demas Axel Marfiansyah** (database)<br>
Dari: Hanif (integrasi GitHub)<br>
Tanggal: 4 November 2026<br>
Status: **skema UC04/UC05 sudah diimplementasikan sebagai migration aditif dan diverifikasi di MySQL 8.4.3.** Bagian 4 mempertahankan konteks usulan awal dan bagian 5 mencatat keputusan; status final ada di §1, §2, dan §7.

## 1. Kondisi aktual

Migration yang sudah ada sebelum W13-01:

- `analysis_batch`, `ulasan`, `maintenance_case`, `pengguna_sistem`.
- `maintenance_backlog` sudah dibuat W12-01 (`2026_11_01_000001_create_maintenance_backlog_table.php`) dengan `assigned_to`, `assigned_at`, `source_branch`, status, dan unique `case_id`.
- `2026_11_01_000002_add_uc03_index_hardening.php` hanya memastikan index UC03 ada.

Lima migration baru UC04/UC05 bersifat aditif dan tidak menghapus atau mengganti kolom W12-01:

| Migration | Isi |
| --- | --- |
| `2026_11_04_000001_create_github_repository_table` | Tabel `github_repository`. |
| `2026_11_04_000002_add_uc04_uc05_fields_to_maintenance_backlog` | `repository_id`, `last_push_sha`, `last_push_at`; index `repository_id`/`assigned_to`/`source_branch`; `source_branch` → 255; FK repository. |
| `2026_11_04_000003_add_github_login_to_pengguna_sistem` | `github_login` nullable unique. |
| `2026_11_04_000004_create_pull_request_table` | Tabel `pull_request` + constraint idempotensi. |
| `2026_11_04_000005_create_github_webhook_delivery_table` | Tabel ledger `github_webhook_delivery`. |

Tabel `code_review` sengaja **belum dibuat** karena review/approve/merge UC06 masih di luar scope. Token GitHub **tidak disimpan di database**; token tetap berasal dari environment/secret manager.

## 2. Skema final yang sudah diterapkan

### 2.1 `github_repository`

| Kolom | Tipe | Null | Constraint |
| --- | --- | --- | --- |
| `repository_id` | bigint unsigned PK | tidak | auto increment. |
| `github_repository_id` | bigint unsigned | ya | unique bila diisi. |
| `owner` | varchar(100) | tidak | — |
| `name` | varchar(100) | tidak | — |
| `full_name` | varchar(201) | tidak | unique. |
| `default_branch` | varchar(255) | ya | — |
| `html_url` | varchar(500) | ya | — |
| `is_private` | boolean | tidak | default `false`. |
| `last_checked_at` | timestamp | ya | — |
| `created_at`, `updated_at` | timestamp | ya | — |

Owner/name/full_name berasal dari konfigurasi server, bukan input browser. Metadata GitHub disinkronkan setelah pemeriksaan koneksi berhasil.

### 2.2 `maintenance_backlog` — kolom final

Kolom yang sudah ada sejak W12-01: `backlog_id` (PK), `case_id` (FK `maintenance_case`, unique, `restrict`), `assigned_to` (FK `pengguna_sistem`, nullable, `set null`), `assigned_at`, `source_branch`, `status`, `created_at`, `updated_at`.

Tambahan UC04/UC05:

| Kolom | Tipe | Null | Keterangan |
| --- | --- | --- | --- |
| `repository_id` | bigint unsigned FK `github_repository.repository_id` | ya | `nullOnDelete`; index. |
| `last_push_sha` | char(40) | ya | Hanya diisi setelah branch/commit GitHub terverifikasi. |
| `last_push_at` | timestamp | ya | Waktu push terverifikasi. |

Perubahan lain:

- `source_branch` diperlebar dari varchar(100) ke varchar(255), tanpa memotong nilai lama.
- Index `assigned_to` dan `source_branch` ditambahkan untuk pencarian issue aktif dan validasi branch.
- Unique `case_id` dari W12-01 tetap dipertahankan (BR-10).

Catatan: field tampilan seperti `judul_issue`, `ringkasan_masalah`, `severity`, dan `modul` **tidak ditambahkan ke backlog** karena sudah berasal dari `maintenance_case`; endpoint detail menggabungkan data case dan backlog. Pada kontrak API saat ini, `priority` diturunkan dari `severity` (`High`/`Medium`/`Low`) dan `priority_score` berasal dari `maintenance_case`, bukan kolom baru di backlog.

### 2.3 `pengguna_sistem`

| Kolom | Tipe | Null | Constraint |
| --- | --- | --- | --- |
| `github_login` | varchar(100) | ya | unique. |

Dipakai webhook untuk memastikan aktor push adalah assignee issue. Akun tanpa pemetaan tetap dapat memakai alur manual/read-only, tetapi webhook akan menolak transisi dengan `WEBHOOK_ASSIGNEE_UNMAPPED`. Isi kolom ini sebelum mengandalkan webhook di production.

### 2.4 `pull_request`

| Kolom | Tipe | Null | Keterangan |
| --- | --- | --- | --- |
| `pull_request_id` | bigint unsigned PK | tidak | auto increment. |
| `backlog_id` | bigint unsigned FK `maintenance_backlog` | tidak | `restrict`. |
| `developer_id` | bigint unsigned FK `pengguna_sistem.user_id` | tidak | `restrict`. |
| `repository_id` | bigint unsigned FK `github_repository` | ya | `nullOnDelete`. |
| `repository_full_name` | varchar(201) | ya | Snapshot identitas repository saat PR dibuat. |
| `source_branch` | varchar(255) | tidak | — |
| `target_branch` | varchar(255) | tidak | — |
| `title` | varchar(255) | tidak | — |
| `description` | text | tidak | Dikirim sebagai body PR. |
| `github_pr_id` | bigint unsigned | ya | unique bila diisi. |
| `github_pr_number` | bigint unsigned | ya | unique bila diisi. |
| `github_pr_url` | varchar(500) | ya | — |
| `github_head_sha` | char(40) | ya | Head saat sinkronisasi. |
| `github_base_sha` | char(40) | ya | Base saat sinkronisasi. |
| `submission_state` | enum | tidak | `pending`, `created`, `failed`, `reconciliation_required`; default `pending`. |
| `lifecycle_state` | enum | ya | `open`, `closed`, `merged`; hanya dari respons GitHub. |
| `request_fingerprint` | char(64) | tidak | unique; idempotensi request. |
| `idempotency_key` | varchar(255) | ya | Opsional dari client. |
| `last_error_code` | varchar(100) | ya | Kode error terakhir yang aman ditampilkan. |
| `reconciled_at` | timestamp | ya | Waktu rekonsiliasi berhasil. |
| `created_at`, `updated_at` | timestamp | ya | — |

Constraint/index:

- Unique `request_fingerprint`.
- Unique `(backlog_id, idempotency_key)` — banyak NULL diperbolehkan.
- Unique `(backlog_id, source_branch, target_branch, github_head_sha)` — mencegah dua PR untuk head/base yang sama.
- Index `(backlog_id, submission_state)` dan `(backlog_id, lifecycle_state)`.
- Unique `github_pr_id` dan `github_pr_number` bila tidak null.
- FK `backlog_id` dan `developer_id` memakai `RESTRICT`; `repository_id` memakai `SET NULL`.

### 2.5 `github_webhook_delivery`

| Kolom | Tipe | Null | Keterangan |
| --- | --- | --- | --- |
| `webhook_delivery_id` | bigint unsigned PK | tidak | auto increment. |
| `github_delivery_id` | varchar(100) | tidak | unique; kunci deduplikasi/replay. |
| `event` | varchar(50) | tidak | Contoh `push`, `ping`. |
| `action` | varchar(50) | ya | — |
| `repository_full_name` | varchar(201) | ya | — |
| `ref` | varchar(255) | ya | — |
| `after_sha` | char(40) | ya | — |
| `status` | enum | tidak | `received`, `processed`, `ignored`, `failed`; default `received`. |
| `backlog_id` | bigint unsigned FK `maintenance_backlog` | ya | `nullOnDelete`. |
| `error_code` | varchar(100) | ya | — |
| `processed_at` | timestamp | ya | — |
| `created_at`, `updated_at` | timestamp | ya | — |

Index `(event, status)` membantu operasi pemantauan/retry. Delivery `failed` boleh di-retry dengan ID yang sama; delivery yang sudah `processed`/`ignored` tidak diproses ulang.

## 3. Relasi final

```text
maintenance_case 1 ── 0..1 maintenance_backlog
github_repository 1 ── 0..* maintenance_backlog
maintenance_backlog 1 ── 0..* pull_request
maintenance_backlog 1 ── 0..* github_webhook_delivery
pengguna_sistem 1 ── 0..* maintenance_backlog (assigned_to)
pengguna_sistem 1 ── 0..* pull_request (developer_id)
pengguna_sistem 0..1 ── github_login (pemetaan webhook)
```

`github_repository` tidak harus terisi saat backlog dibuat. Repository ditautkan saat konfigurasi/klaim pertama dan dapat berubah mengikuti konfigurasi server; gunakan `repository_full_name` pada PR sebagai snapshot agar metadata PR lama tidak berubah saat repository di-relink.

## 4. Usulan awal dan perbedaannya dengan implementasi

Usulan awal dipertahankan sebagai konteks keputusan. Ringkasan perbedaannya:

| Usulan awal | Implementasi final |
| --- | --- |
| `maintenance_backlog` menyimpan `judul_issue`, `ringkasan_masalah`, `priority`, `severity`, `modul` | Tidak ditambahkan; data tersebut sudah ada di `maintenance_case`. |
| `source_branch` varchar(255) | Diterapkan. |
| `last_push_sha` varchar(64) | Diterapkan sebagai char(40) karena SHA GitHub 40 karakter. |
| `pull_request` hanya metadata dasar | Ditambah `repository_full_name`, `github_pr_id`, `github_head_sha`, `github_base_sha`, `submission_state`, `request_fingerprint`, `idempotency_key`, `last_error_code`, `reconciled_at`. |
| Belum ada tabel event webhook; keputusan push disimpan di `last_push_*` | Ditambah tabel ledger `github_webhook_delivery` untuk deduplikasi/replay; `last_push_*` tetap menjadi status push terakhir. |
| `code_review` disiapkan untuk UC06 | Belum dibuat karena UC06 di luar scope. |
| Pemetaan identitas GitHub belum diputuskan | Ditambah `pengguna_sistem.github_login` nullable unique. |
| Path final masih menunggu kesepakatan | Route produksi `/maintenance-backlogs/...`, `/developer/maintenance-issues`, `/pull-requests/...`, `/webhooks/github` sudah diimplementasikan; mock `/developer/github-mock/*` tetap dipertahankan. |

## 5. Keputusan: selesai dan masih terbuka

Sudah diputuskan/diterapkan:

1. Nama tabel dan PK: `maintenance_backlog.backlog_id`, `github_repository.repository_id`, `pull_request.pull_request_id`, `github_webhook_delivery.webhook_delivery_id`.
2. `maintenance_backlog` dibuat W12-01 dan tidak dibuat ulang oleh W13-01.
3. Token tetap di environment/secret manager; tidak ada secret di tabel repository.
4. Identitas GitHub Developer dipetakan lewat `pengguna_sistem.github_login` (opsional, unique).
5. Event push memakai kombinasi `last_push_*` pada backlog dan ledger `github_webhook_delivery`.

Masih terbuka:

1. Apakah `assigned_to` boleh berpindah setelah Request Changes (UC06) atau tetap satu Developer.
2. Penyimpanan token terenkripsi di database bila D07 kelak menyetujuinya.
3. Kebutuhan dua akun reviewer dan pemetaan reviewer GitHub untuk UC06.
4. Kebijakan retensi baris `github_webhook_delivery` dan `pull_request` yang sudah lama.
5. Backfill `github_login` untuk akun Developer yang sudah ada di environment production.

## 6. Urutan migration aktual

1. `2026_11_04_000001_create_github_repository_table`
2. `2026_11_04_000002_add_uc04_uc05_fields_to_maintenance_backlog`
3. `2026_11_04_000003_add_github_login_to_pengguna_sistem`
4. `2026_11_04_000004_create_pull_request_table`
5. `2026_11_04_000005_create_github_webhook_delivery_table`

Urutan ini sudah memenuhi dependensi FK: repository sebelum backlog alteration, backlog sebelum PR/delivery. Rollback harus berurutan terbalik.

## 7. Verifikasi MySQL

Lingkungan: MySQL **8.4.3**, database `maintaingov`, 4 November 2026.

Yang diperiksa langsung:

- Semua 15 migration berstatus `Ran`.
- Tabel `github_repository`, `pull_request`, dan `github_webhook_delivery` ada.
- Kolom `maintenance_backlog.repository_id`, `last_push_sha`, `last_push_at` ada; `source_branch` bertipe `varchar(255)`.
- Kolom `pengguna_sistem.github_login` ada dan unique nullable.
- Unique `pull_request_request_fingerprint_unique`, `pull_request_backlog_idempotency_unique`, `pull_request_backlog_head_unique`, unique `github_pr_id`/`github_pr_number`, unique `github_delivery_id`, serta unique repository `full_name`/`github_repository_id` ada.
- FK repository/backlog/developer/delivery ada dengan aturan `SET NULL`/`RESTRICT` sesuai migration.
- Insert transaksional untuk case, repository, backlog, PR, dan delivery berhasil; setelah `ROLLBACK`, tidak ada baris verifikasi tersisa.
- Rollback 5 migration UC04/UC05 berhasil pada database SQLite sementara. Rollback MySQL tidak dijalankan pada database lokal untuk menghindari kehilangan data; constraint MySQL diperiksa lewat `SHOW CREATE TABLE`/`information_schema`.

Catatan lingkungan: database lokal `maintaingov` pernah di-`migrate:fresh` saat verifikasi up-migration. Hanya akun uji yang di-seed ulang; data non-akun lokal tidak dipertahankan. Jangan mengulang `migrate:fresh` pada database yang berisi data penting.

## 8. Catatan operasional untuk Demas

- Hapus data uji dengan urutan: `github_webhook_delivery` → `pull_request` → `maintenance_backlog` → `github_repository` (FK `RESTRICT` pada PR/backlog).
- `assigned_to` memakai `SET NULL`; menghapus user tidak menghapus backlog, tetapi assignment menjadi null.
- `repository_id` memakai `SET NULL`; metadata repository pada PR tetap tersimpan lewat `repository_full_name`.
- `github_login` harus unik dan sebaiknya diisi dengan login GitHub aktual, bukan email.
- Jangan menambahkan token, header otorisasi, atau body mentah GitHub ke tabel mana pun.
- Migration baru tidak mengubah kontrak mock Week 1 dan tidak menghapus kolom W12-01.

## 9. Rujukan

- [Kontrak API nyata UC04–UC05](../contracts/uc04-uc05-real-api.md)
- [Implementasi, bukti uji, dan handoff UC04–UC05](UC04_UC05_BACKEND.md)
- [Integrasi GitHub](GITHUB_INTEGRATION.md)
- [Delta PDM Sprint 1](artifacts/shared/pdm-delta-sprint1.md)
