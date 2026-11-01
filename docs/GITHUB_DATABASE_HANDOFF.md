# Handoff Database — Koneksi GitHub dan UC04–UC05

Untuk: **Demas Axel Marfiansyah** (database)  
Dari: Hanif (integrasi GitHub)  
Status: **usulan, belum ada migration yang dibuat**. Jangan dianggap skema ini sudah disetujui atau berjalan.

## 1. Kondisi aktual

Migration Laravel saat ini berhenti pada alur Sprint 1:

- `analysis_batch`, `ulasan`, `maintenance_case`, `pengguna_sistem`.
- Belum ada tabel `maintenance_backlog`, `github_repository`, `pull_request`, atau `code_review`.
- Fitur Feature 9 saat ini hanya melakukan baca metadata repository dan menyediakan mock UC04–UC05 tanpa persistensi.

Delta yang sudah tercatat tetapi belum dieksekusi ada di [pdm-delta-sprint1.md](artifacts/shared/pdm-delta-sprint1.md): `assigned_to`, `assigned_at`, `source_branch`, unique `case_id`, serta tabel GitHub. Dokumen ini memperjelas field minimum yang dibutuhkan implementasi nyata.

## 2. Kebutuhan minimum per tabel

### `maintenance_backlog`

Alasan: UC04 membutuhkan assignment atomik dan branch sumber; UC05 membutuhkan status serta relasi PR.

| Field | Tipe usulan | Null | Keterangan |
| --- | --- | --- | --- |
| `backlog_id` | bigint PK | tidak | ID issue/backlog internal. |
| `case_id` | bigint FK `maintenance_case.case_id` | tidak | Satu backlog utama per case valid. |
| `repository_id` | bigint FK `github_repository.repository_id` | ya | Repository tujuan; boleh null selama konfigurasi belum ditautkan. |
| `judul_issue` | varchar(200) | tidak | Judul yang tampil di UI. |
| `ringkasan_masalah` | text | tidak | Ringkasan pekerjaan. |
| `priority` | varchar(20)/enum | tidak | Nilai mengikuti kontrak UI. |
| `severity` | varchar(20)/enum | tidak | Nilai mengikuti kontrak UI. |
| `modul` | varchar(100) | tidak | Modul terkait. |
| `status` | enum | tidak | Minimal `Ready for Development`, `Sedang Dikerjakan`, `Ready for Pull Request`, `Ready for Merge`. |
| `assigned_to` | bigint FK `pengguna_sistem.user_id` | ya | Developer penerima; null sebelum klaim. |
| `assigned_at` | timestamp | ya | Waktu klaim. |
| `source_branch` | varchar(255) | ya | Branch kerja Developer; wajib saat status siap PR. |
| `last_push_sha` | varchar(64) | ya | SHA push terakhir yang terverifikasi. |
| `last_push_at` | timestamp | ya | Waktu event push diterima/diverifikasi. |
| `created_at`, `updated_at` | timestamp | ya | Audit waktu. |

Constraint/index yang disarankan:

- `UNIQUE(case_id)` untuk BR-10.
- Index `(status)`, `(assigned_to)`, `(repository_id)`.
- FK `assigned_to` dan `repository_id` dengan `restrict`/`nullOnDelete` sesuai keputusan Demas.
- Jangan memakai `assigned_to` untuk menyimpan username GitHub; simpan user internal dan petakan identitas GitHub terpisah bila diperlukan.

### `github_repository`

Alasan: metadata repository hasil pemeriksaan koneksi perlu dapat ditautkan ke backlog/PR, bukan hanya disimpan di env.

| Field | Tipe usulan | Null | Keterangan |
| --- | --- | --- | --- |
| `repository_id` | bigint PK | tidak | ID internal. |
| `github_repository_id` | bigint/unsigned bigint | ya | ID numerik GitHub; unique bila diisi. |
| `owner` | varchar(100) | tidak | Contoh `AgilePemdigi-2026`. |
| `name` | varchar(100) | tidak | Contoh `MaintainGov`. |
| `full_name` | varchar(201) | tidak | `owner/name`; unique. |
| `default_branch` | varchar(255) | ya | Contoh `main`. |
| `html_url` | varchar(500) | ya | URL repository. |
| `is_private` | boolean | tidak | Status visibilitas. |
| `last_checked_at` | timestamp | ya | Waktu pemeriksaan koneksi terakhir. |
| `created_at`, `updated_at` | timestamp | ya | Audit. |

Catatan keamanan: **jangan menyimpan token GitHub di tabel ini** kecuali D07 secara eksplisit menyetujui penyimpanan secret terenkripsi. Untuk saat ini token hanya berasal dari environment/secret manager. Permission (`admin`, `maintain`, `push`, `triage`, `pull`) hanya metadata respons dan tidak perlu disimpan kecuali ada kebutuhan audit.

### `pull_request`

Alasan: FR-UC05-11 mewajibkan metadata PR tersimpan; BR-18 mengizinkan satu backlog memiliki lebih dari satu PR.

| Field | Tipe usulan | Null | Keterangan |
| --- | --- | --- | --- |
| `pull_request_id` | bigint PK | tidak | ID internal. |
| `backlog_id` | bigint FK `maintenance_backlog.backlog_id` | tidak | PR terkait issue. |
| `developer_id` | bigint FK `pengguna_sistem.user_id` | tidak | Developer pengaju. |
| `source_branch` | varchar(255) | tidak | Branch sumber. |
| `target_branch` | varchar(255) | tidak | Branch tujuan. |
| `title` | varchar(255) | tidak | Judul PR. |
| `description` | text | ya | Deskripsi PR. |
| `github_pr_number` | unsigned bigint | ya | Nomor PR nyata; unique bila diisi. |
| `github_pr_url` | varchar(500) | ya | URL PR nyata. |
| `lifecycle_state` | enum | tidak | `open`, `closed`, `merged`; pisahkan dari status review. |
| `review_state` | enum | tidak | `pending`, `approved`, `changes_requested` (UC06). |
| `github_head_sha` | varchar(64) | ya | SHA head saat sinkronisasi; membantu invalidasi review. |
| `last_synced_at` | timestamp | ya | Waktu sinkronisasi terakhir. |
| `created_at`, `updated_at` | timestamp | ya | Audit. |

Constraint/index:

- Index `(backlog_id)`, `(lifecycle_state)`, `(review_state)`.
- Unique `github_pr_number` bila tidak null. MySQL mengizinkan banyak NULL pada unique index.
- Jangan menandai PR `Open` sebelum respons GitHub sukses. Untuk retry setelah timeout, cari PR berdasarkan `github_pr_number`/head SHA sebelum membuat ulang (NFR-SAF-09).

### `code_review` (UC06, di luar scope Feature 9)

Minimal: `code_review_id`, `pull_request_id`, `reviewer_id` FK pengguna, `decision`, `notes`, `reviewed_at`, `github_review_id` (nullable/unique), `created_at`. Catatan ini hanya pengingat agar skema PR tidak menutup kebutuhan UC06.

## 3. Relasi

```text
maintenance_case 1 ── 0..1 maintenance_backlog
github_repository 1 ── 0..* maintenance_backlog
maintenance_backlog 1 ── 0..* pull_request
pull_request 1 ── 0..* code_review
pengguna_sistem 1 ── 0..* maintenance_backlog (assigned_to)
pengguna_sistem 1 ── 0..* pull_request (developer_id)
```

`github_repository` tidak harus terisi saat backlog dibuat; tautan repository dapat ditambahkan saat konfigurasi disetujui. Jangan mengubah kontrak endpoint Axel/Valisha tanpa membahas path final.

## 4. Keputusan yang menunggu konfirmasi

1. Nama tabel dan primary key final (`backlog_id` vs `issue_id`, `repository_id` vs `github_repository_id`).
2. Apakah `maintenance_backlog` dibuat oleh UC02/UC03 dan kapan migration-nya dieksekusi.
3. Apakah `assigned_to` boleh berpindah setelah Request Changes (UC06) atau tetap satu Developer.
4. Penyimpanan token: environment saja (rekomendasi saat ini) atau encrypted secret di database (D07).
5. Pemetaan identitas GitHub pengguna internal dan kebutuhan dua akun reviewer (D09/UC06).
6. Path final API: arsitektur menyebut `/maintenance-backlogs/...`; mock Feature 9 memakai `/developer/github-mock/...` agar tidak bentrok.
7. Apakah event push disimpan sebagai tabel riwayat/event tersendiri atau cukup kolom `last_push_*` pada backlog.

## 5. Urutan migration yang disarankan

1. `github_repository` (tidak bergantung pada backlog).
2. `maintenance_backlog` (setelah `maintenance_case`; FK opsional ke repository).
3. `pull_request`.
4. `code_review` (saat UC06 dimulai).

Jangan membuat migration Feature 9 secara sepihak sebelum keputusan di atas dikonfirmasi Demas dan direview. Fitur saat ini tidak membutuhkan tabel baru karena endpoint UC04–UC05 masih mock dan endpoint koneksi hanya membaca environment.
