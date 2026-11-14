# W14-01 — Laporan UC06 Backend: Review Pull Request, Database & Status Maintenance

Tanggal: 2026-11-14 | Eksekusi: sesi agen (atas nama Demas, owner backend) | Reviewer: belum
Branch kerja: `feature/W14-01-uc06-review` (belum dikommit — seluruh perubahan uncommitted)
Acuan: SRS §4.8 UC06 (FR-UC06-01..16), BR-19..22, [kontrak uc06-api.md](../../../contracts/uc06-api.md), [pdm-delta-sprint3.md](../../artifacts/shared/pdm-delta-sprint3.md), decision-log D-W14-01-a..g.

---

## 1. Matriks AC → test

| AC | SRS | Deskripsi | Test (`tests/Feature/PullRequestReviewTest.php`) |
| --- | --- | --- | --- |
| AC1 | FR-UC06-09/10, BR-19 | Catatan review lengkap: reviewer, keputusan, waktu, komentar | `test_approve_happy_path_syncs_github_and_sets_ready_for_merge`, `test_request_changes_syncs_github_and_returns_backlog_to_in_progress`, `test_detail_returns_reviews_and_case_backlog_links` |
| AC2 | FR-UC06-01..04 | List + detail PR lengkap dengan issue/case/backlog | `test_list_is_paginated_and_filterable_by_review_status`, `test_detail_returns_reviews_and_case_backlog_links` |
| AC3 | FR-UC06-07/11, BR-21 | Approve happy-path: sync GitHub, PR `Approved`, `ready_for_merge`, backlog tidak berubah | `test_approve_happy_path_syncs_github_and_sets_ready_for_merge` |
| AC4 | FR-UC06-08/14, BR-20 | Request Changes happy-path: sync GitHub, backlog kembali `Sedang Dikerjakan`, pesan FR-UC06-15 | `test_request_changes_syncs_github_and_returns_backlog_to_in_progress` |
| AC5 | D-W14-01-a | Self-approval ditolak 403 `REVIEW_AUTHOR_NOT_ALLOWED` | `test_self_review_by_the_pull_request_author_is_rejected` |
| AC5b | D-W14-01-d | PR non-Open ditolak 409 `PULL_REQUEST_NOT_OPEN` (belum created / closed / sudah disinkron) | `test_review_is_rejected_when_the_pull_request_is_not_open` |
| AC5c | Kontrak §4.3 | Anonim 401, role salah (Tim Maintenance) 403, PR asing 404, body rusak 422 Indonesia | `test_uc06_endpoints_require_authentication_and_developer_role`, `test_unknown_pull_request_returns_404_envelope`, `test_review_validation_rejects_broken_bodies_in_indonesian` |
| AC6 | D-W14-01-c | Review duplikat reviewer sama tergantikan (upsert, 1 baris) | `test_resubmitted_review_by_the_same_reviewer_replaces_the_previous_one` |
| AC7 | BR-23 | Rantai history Case → Backlog → Issue → Push → PR → Review lengkap aktor + waktu | `test_history_returns_the_full_case_chain`, `test_history_of_a_pending_case_only_contains_the_creation_event`, `test_history_requires_authentication_and_rejects_unknown_cases` |
| AC-sync | D-W14-01-e, FR-UC06-12/13 | Sync gagal bukan sukses palsu: review tersimpan `gagal`, PR tetap Open, warning `meta.sync` | `test_failed_github_sync_stores_the_review_without_a_fake_success` |
| AC-sync2 | FR-UC06-11 | GitHub dipanggil tepat satu kali per keputusan | `Http::assertSentCount(1)` di AC3/AC4 + `Http::assertNothingSent()` di path error |

Jumlah test: file baru 15 test. **Sebelum:** 237 test / 236 pass / 1 skipped / 1.180 asersi (sqlite). **Sesudah sqlite:** 252 / 251 / 1 skipped (by design, CHECK rating MySQL-only) / 1.342 asersi. **Sesudah MySQL:** 252 / 252 pass / **0 skipped** / 1.343 asersi.

## 2. Tabel endpoint

| Endpoint | Method | Role | Memanggil GitHub | Controller |
| --- | --- | --- | --- | --- |
| `/api/v1/pull-requests` | GET | Developer | tidak | `PullRequestController::list` |
| `/api/v1/pull-requests/{id}` | GET | Developer | tidak | `PullRequestController::show` (via `PullRequestReviewService::detail`) |
| `/api/v1/pull-requests/{id}/reviews` | POST | Developer, bukan penulis | ya (POST `/repos/{owner}/{repo}/pulls/{n}/reviews`) | `PullRequestController::storeReview` |
| `/api/v1/maintenance-cases/{case}/history` | GET | Tim Maintenance + Developer | tidak | `MaintenanceCaseController::history` |

## 3. Skema sebelum / sesudah

Sebelum (migration UC05 `2026_11_04_000004`):

- `pull_request`: `submission_state` ENUM(pending/created/failed/reconciliation_required), `lifecycle_state` ENUM(open/closed/merged) nullable, **tanpa kolom review**; status API = field turunan.
- Tabel `code_review`: **tidak ada** (sengaja ditunda untuk UC06 — GITHUB_DATABASE_HANDOFF.md).
- Endpoint UC06 parsial: **tidak ada** (route/mock belum memuat review; `GitHubMockController` hanya fixture `review_state='pending'` statis).

Sesudah (dua migration BARU, migration lama tidak disentuh):

- `2026_11_14_000001_add_review_state_to_pull_request`: kolom `pull_request.review_state` ENUM(pending/approved/changes_requested) NOT NULL DEFAULT `pending` + index `(lifecycle_state, review_state)`.
- `2026_11_14_000002_create_code_review_table`: tabel `code_review` — `review_id` PK; `pull_request_id` FK RESTRICT → `pull_request`; `reviewer_id` FK RESTRICT → `pengguna_sistem`; `decision` ENUM('Approved','Changes Requested'); `comment` TEXT NULL; `reviewed_at`; `github_review_id` UNIQUE; `sync_status` ENUM(pending/synced/gagal) DEFAULT pending; `synced_at`; `last_sync_error_code`; timestamps; **UNIQUE(pull_request_id, reviewer_id)**; index `(pull_request_id, reviewed_at)`.

Bukti MySQL (`maintaingov_w14`, MySQL 8.4.3 Laragon):

```sql
CREATE TABLE `code_review` (
  `review_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pull_request_id` bigint unsigned NOT NULL,
  `reviewer_id` bigint unsigned NOT NULL,
  `decision` enum('Approved','Changes Requested') NOT NULL,
  `comment` text,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `github_review_id` bigint unsigned DEFAULT NULL,
  `sync_status` enum('pending','synced','gagal') NOT NULL DEFAULT 'pending',
  `synced_at` timestamp NULL DEFAULT NULL,
  `last_sync_error_code` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`review_id`),
  UNIQUE KEY `code_review_pull_request_reviewer_unique` (`pull_request_id`,`reviewer_id`),
  UNIQUE KEY `code_review_github_review_id_unique` (`github_review_id`),
  KEY `code_review_pull_request_id_reviewed_at_index` (`pull_request_id`,`reviewed_at`),
  CONSTRAINT `code_review_pull_request_id_foreign` FOREIGN KEY (`pull_request_id`)
    REFERENCES `pull_request` (`pull_request_id`) ON DELETE RESTRICT,
  CONSTRAINT `code_review_reviewer_id_foreign` FOREIGN KEY (`reviewer_id`)
    REFERENCES `pengguna_sistem` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB
-- pull_request.review_state: enum('pending','approved','changes_requested') NOT NULL DEFAULT 'pending'
```

## 4. Keputusan (SRS/BR vs default)

| ID | Keputusan | Basis |
| --- | --- | --- |
| D-W14-01-a | Reviewer = Developer saja, kecuali penulis PR. Default "Developer + Tim Maintenance" **tidak dipakai** | SRS §4.8.2 (aktor = Tim Developer), ARCHITECTURE §2, kontrak UC05 teruji |
| D-W14-01-b | Approve → `review_state=approved` + label `Ready for Merge` (bukan enum); backlog `Selesai` hanya saat GitHub melapor `merged` | BR-20/21/22, FR-UC06-12..16 |
| D-W14-01-c | Satu review aktif per reviewer (UNIQUE + upsert) | default W14-01-c, mendukung retry sync |
| D-W14-01-d | Review hanya saat PR Open; selain itu 409 | default W14-01-d + precondition SRS |
| D-W14-01-e | Dua fase transaksi; GitHub di luar transaksi; gagal sync → `sync_status=gagal` + warning `meta.sync`, bukan sukses palsu | FR-UC06-12/13 + pola UC05 |
| D-W14-01-f | Tabel `code_review` (bukan `pull_request_reviews`) + kolom sinkron | SRS data mapping §4.8.4 |
| D-W14-01-g | History dibuka untuk kedua role (read-only); list/detail/review tetap Developer | default (SRS diam), konsisten ARCHITECTURE §2 |

## 5. Hasil MySQL (evidence)

| Langkah | Hasil | Log |
| --- | --- | --- |
| `CREATE DATABASE maintaingov_w14` | OK | — |
| `php artisan migrate:fresh --seed --force` (DB `maintaingov_w14`) | OK; 2 migration W14 ikut jalan (tercantum di log) | `mysql-migrate-seed-w14-01.log` |
| `php artisan test --compact` (MySQL) | **252 test, 252 pass, 0 gagal, 0 skipped, 1.343 asersi** | `mysql-test-run-w14-01.log` |

Catatan eksekusi: phpunit.xml menetapkan `DB_CONNECTION=sqlite` (tanpa `force`), sehingga run MySQL memerlukan `DB_CONNECTION=mysql` + `DB_DATABASE=maintaingov_w14` pada proses (pola yang sama dengan evidence W13). `migrate:fresh --seed` dijalankan hanya dengan override `DB_DATABASE` mengikuti penamaan DB per-week (`maintaingov_w14`); `.env` tidak diubah dan tidak ada override liar lain. Skipped 0 — CHECK `ulasan.rating` ikut teruji di MySQL (test yang skip di sqlite lulus di sini).

## 6. File dibuat / diubah (git status)

Diubah:

- `apps/api/routes/api.php` (route UC06 + import controller)
- `apps/api/app/Http/Controllers/PullRequestController.php` (list, show→service detail, storeReview)
- `apps/api/app/Http/Controllers/MaintenanceCaseController.php` (history)
- `apps/api/app/Models/PullRequest.php` (review_state fillable + relasi reviews)
- `apps/api/app/Services/GitHub/GitHubService.php` (createPullRequestReview + normalizer)
- `apps/api/app/Services/PullRequest/PullRequestService.php` (map() public + field review additif)
- `docs/artifacts/shared/decision-log.md` (D-W14-01-a..g)

Baru:

- `apps/api/database/migrations/2026_11_14_000001_add_review_state_to_pull_request.php`
- `apps/api/database/migrations/2026_11_14_000002_create_code_review_table.php`
- `apps/api/app/Models/CodeReview.php`
- `apps/api/app/Services/PullRequest/PullRequestReviewService.php`
- `apps/api/app/Http/Requests/ListPullRequestRequest.php`, `StorePullRequestReviewRequest.php`
- `apps/api/tests/Feature/PullRequestReviewTest.php` (15 test)
- `contracts/uc06-api.md`, `docs/artifacts/shared/pdm-delta-sprint3.md`, `docs/artifacts/uc06/review-sequence.puml`, `docs/evidence/w14/*`
- (`docs/artifacts/shared/github-integration-audit-W13.md` untracked dari W13 — bukan file W14.)

## 7. Pernyataan & batasan

- **Tidak ada migration lama yang diedit** — semua perubahan skema lewat dua migration baru bertanggal `2026_11_14_*`.
- **Tidak ada commit** — seluruh perubahan masih uncommitted di branch `feature/W14-01-uc06-review`.
- **`.env.example` tidak disentuh.**
- **Handoff Valisha siap** — sezione "Handoff Frontend" di `contracts/uc06-api.md` §5 memuat field list/detail/aksi review/timeline riwayat + label Indonesia per event.
- Batasan: sync status `merged` (FR-UC06-16) belum diimplementasi (di luar W14-01); live-GitHub smoke review BLOCKED (token belum tersedia) — tidak ada klaim PASS live; SVG sequence belum dirender (PlantUML tidak tersedia di lingkungan; `.puml` tersedia).
