# Delta PDM Sprint 3 — MaintainGov (W14-01, UC06 Review Pull Request)
Penanggung jawab: Demas Axel Marfiansyah | Reviewer: Hanif
Acuan: PDM SRS Gambar 6.3 (data mapping §4.8.4: `pull_request`, `code_review`, `maintenance_backlog`, `pengguna_sistem`); SRS FR-UC06-01..16, BR-19..22; ARCHITECTURE.md §5–§6; pdm-delta-sprint1.md, pdm-delta-sprint2.md; kontrak [uc06-api.md](../../../contracts/uc06-api.md).
Status: dicatat SEBELUM migration W14-01 dibuat. Migration lama tidak diedit; semua perubahan lewat migration baru.

## Delta yang DIEKSEKUSI pada W14-01 (Sprint 3)

| Tabel | Perubahan terhadap PDM | Alasan / acuan |
| --- | --- | --- |
| code_review | Tabel baru: `review_id` PK; `pull_request_id` unsignedBigInteger FK `ON DELETE RESTRICT` ke `pull_request(pull_request_id)`; `reviewer_id` unsignedBigInteger FK `ON DELETE RESTRICT` ke `pengguna_sistem(user_id)`; `decision` ENUM 2 nilai (`Approved`, `Changes Requested`); `comment` TEXT nullable (wajib secara aplikasi saat `Changes Requested`, FR-UC06-08/10); `reviewed_at` timestamp nullable (BR-19: reviewer, keputusan, waktu, catatan); `created_at`/`updated_at` | SRS data mapping §4.8.4 menamai tabel **`code_review`** (bukan `pull_request_reviews`); nama mengikuti SRS dan konvensi repo. D-W14-01-f |
| code_review (sinkronisasi GitHub) | `github_review_id` unsignedBigInteger nullable UNIQUE; `sync_status` ENUM 3 nilai (`pending`, `synced`, `gagal`) default `pending`; `synced_at` timestamp nullable; `last_sync_error_code` varchar(100) nullable | FR-UC06-11: keputusan dikirim ke GitHub; status transisi hanya berubah "ketika berhasil disinkronkan" (FR-UC06-12/13); larangan sukses palsu mengikuti pola UC05 |
| code_review (index) | UNIQUE `(pull_request_id, reviewer_id)` — satu review aktif per reviewer (D-W14-01-c, review baru menggantikan yang lama); index `pull_request_id` untuk listing per PR | D-W14-01-c; performa list §4.1/§4.2 |
| pull_request | Kolom baru `review_state` ENUM 3 nilai (`pending`, `approved`, `changes_requested`) NOT NULL DEFAULT `pending` — VIA MIGRATION BARU (dilarang edit `2026_11_04_000004_create_pull_request_table.php`) | BR-20/21; pemisahan status review aplikasi dari `lifecycle_state` GitHub sesuai usulan mapping ARCHITECTURE §6 (`review_state = pending/approved/changes_requested`). `Ready for Merge`/`Merged` **tetap** bukan enum kolom baru: `lifecycle_state` sudah memuat `merged`, dan `Ready for Merge` adalah label tampilan ketika `review_state = approved` (D-W14-01-b) |

`status` pada respons API tetap field turunan (`lifecycle_state` ?? `submission_state`) agar kontrak UC05 §4.3 tidak berubah; label SRS (`Open`, `Approved`, `Changes Requested`, `Merged`, `Closed`) diekspos sebagai field `review_status` + `ready_for_merge` (kontrak §3).

## Keputusan status yang menutup deferred Sprint 1–2

| Item deferred | Keputusan W14-01 |
| --- | --- |
| ENUM `Ready for Merge` pada `pull_request` (pdm-delta-sprint1/2) | Tidak menjadi kolom/enum. `review_state = approved` + `lifecycle_state = open` → frontend menampilkan `Ready for Merge` (`ready_for_merge = true`, `status_label = Ready for Merge`). Sesuai BR-21: Approve mengubah PR menjadi `Approved` dan *memungkinkan* dilanjutkan ke `Ready for Merge` |
| Pemisahan Closed vs Merged (ARCHITECTURE §5) | Sudah tercakup oleh `lifecycle_state` ENUM (`open`, `closed`, `merged`) sejak migration UC05; transisi ke `merged` hanya dari sinkronisasi/event GitHub (BR-22) |
| Transisi backlog | Approve **tidak** mengubah backlog. `Selesai` hanya saat PR `merged` tersinkron (BR-22). Request Changes mengembalikan backlog ke `Sedang Dikerjakan` (BR-20, FR-UC06-14) |
| Riwayat perubahan status (ARCHITECTURE §5, baris Sprint 2–3) | Endpoint `GET /maintenance-cases/{case}/history` merakit timeline dari data eksisting (case, backlog, assignment, push, PR, review) — belum ada tabel event historis; pencatatan event penuh (actor per transisi status PR) menyusul bila fitur riwayat menuntut |

## Sequence diagram alur review UC06

Sumber: `docs/artifacts/uc06/review-sequence.puml`. Ringkasan alur:

1. Reviewer (Developer) `POST /pull-requests/{id}/reviews`.
2. Laravel memvalidasi body (422), memuat PR (404), menolak penulis PR (403), menolak PR non-Open (409).
3. Transaksi 1: kunci baris PR (`lockForUpdate`), cek ulang status, upsert `code_review` (`sync_status=pending`, menggantikan review lama reviewer sama), commit.
4. Adapter GitHub `POST /repos/{owner}/{repo}/pulls/{number}/reviews` (`APPROVE` / `REQUEST_CHANGES`).
5. Sukses → Transaksi 2: kunci ulang PR; bila masih `pending`, tulis `review_state` baru + `sync_status=synced` + `synced_at`; Request Changes → backlog kembali `Sedang Dikerjakan`; commit; response 200.
6. Gagal → Transaksi 2 menulis `sync_status=gagal` + `last_sync_error_code`; PR tetap `Open`; response 200 dengan peringatan `meta.sync` (bukan sukses palsu).
7. Status backlog `Selesai` hanya ketika sinkronisasi terpisah melaporkan PR `merged` (BR-22; di luar W14-01).

## Pemeriksaan skema eksisting (fakta, file:baris)

| Objek | Status | Catatan |
| --- | --- | --- |
| `pull_request` ENUM `submission_state` (pending/created/failed/reconciliation_required) | ada | `2026_11_04_000004_create_pull_request_table.php:36-41` |
| `pull_request` ENUM `lifecycle_state` (open/closed/merged) nullable | ada | ibid:42 |
| `pull_request` belum memiliki kolom status/review | fakta | `status` pada API adalah field turunan (`PullRequestService.php:629`) |
| Tabel `code_review` belum ada | fakta | GITHUB_DATABASE_HANDOFF.md §154: sengaja ditunda untuk UC06 |
| Endpoint UC06 parsial | tidak ada | `routes/api.php` + `routes/github.php` belum memuat route review; mock `GitHubMockController` hanya fixture `review_state='pending'` statis |
