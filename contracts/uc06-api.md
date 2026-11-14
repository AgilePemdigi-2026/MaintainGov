# Kontrak API — UC06 Review Pull Request

Versi: 1.0 (W14-01, 14 November 2026)<br>
Status: **endpoint review PR nyata dan persisten**. Keputusan review dikirim ke GitHub REST API sebelum status PR berubah; tidak ada sukses palsu.<br>
Branch kerja: `feature/W14-01-uc06-review`<br>
Acuan: SRS §4.8 UC06 (FR-UC06-01..16), BR-19..22, [arsitektur](../docs/ARCHITECTURE.md) §4/§6, dan [`uc04-uc05-real-api.md`](uc04-uc05-real-api.md).

> Dokumen ini **melengkapi** `uc04-uc05-real-api.md`. Endpoint UC05 (claim, push, PR) tidak berubah; field baru pada detail PR bersifat **additive** (§4.2).

## 1. Ruang lingkup

| Area | Endpoint | Memanggil GitHub | Menulis database |
| --- | --- | --- | --- |
| Daftar PR (untuk halaman review) | `GET /api/v1/pull-requests` | tidak | tidak |
| Detail PR + daftar review | `GET /api/v1/pull-requests/{pullRequest}` | tidak | tidak |
| Beri review (Approve / Request Changes) | `POST /api/v1/pull-requests/{pullRequest}/reviews` | ya, `POST /repos/{owner}/{repo}/pulls/{number}/reviews` | ya |
| Riwayat Case → Backlog → Issue → PR → Review | `GET /api/v1/maintenance-cases/{case}/history` | tidak | tidak |

Di luar ruang lingkup W14-01 (mengikuti batas kontrak UC05):

- Sinkronisasi status `Merged` (FR-UC06-16) — membutuhkan event/sync GitHub eksternal; jalur datanya sudah disiapkan (`lifecycle_state='merged'`), implementasinya menyusul.
- Diff dan hasil checks (FR-UC06-05/06) — ditampilkan frontend dari GitHub; endpoint read backend sengaja tidak memanggil GitHub (konsisten dengan kontrak UC05 §3.1/§4.3).
- Tindakan merge/deployment — BR-22: MaintainGov tidak pernah merge.

## 2. Konvensi umum

- Base URL `/api/v1`; session cookie + `X-CSRF-TOKEN` (lihat UC05 §2).
- Role: `GET /pull-requests`, `GET /pull-requests/{id}`, `POST /pull-requests/{id}/reviews` → **Developer** (SRS §4.8.2: aktor UC06 adalah Tim Developer; lihat decision-log D-W14-01-a). `GET /maintenance-cases/{case}/history` → **Tim Maintenance dan Developer** (read-only, D-W14-01-g).
- Waktu ISO 8601, UTC. Token GitHub tidak pernah keluar dari server.
- Envelope sukses/error mengikuti UC05 §2.1–2.3. Error validasi memakai `error.code = VALIDATION_ERROR` + `errors` berbahasa Indonesia.

## 3. Status Pull Request (hasil W14-01)

Tabel `pull_request` memperoleh kolom baru `review_state` (`pending`, `approved`, `changes_requested`; migration baru, lihat [pdm-delta-sprint3](../docs/artifacts/shared/pdm-delta-sprint3.md)). `lifecycle_state` tetap kebenaran GitHub (`open`/`closed`/`merged`).

Field turunan yang dipakai frontend:

| Field | Nilai | Sumber |
| --- | --- | --- |
| `status` | `lifecycle_state` bila ada, selain itu `submission_state` | tidak berubah dari UC05 |
| `review_state` | `pending` / `approved` / `changes_requested` | kolom baru |
| `review_status` | Label SRS: `Open`, `Approved`, `Changes Requested`, `Merged`, `Closed`; fallback `status` untuk PR yang belum terbentuk | turunan |
| `ready_for_merge` | `true` bila `review_state = approved` dan `lifecycle_state = open` | turunan |
| `status_label` | Label tampilan; **ketika `ready_for_merge = true` tampilkan `Ready for Merge`** (FR-UC06-12) | turunan |

Aturan status (BR-20/21, FR-UC06-12/13):

1. PR dibuat → `review_status = Open`.
2. Keputusan **Approve tersinkron ke GitHub** → `review_state = approved`, `review_status = Approved`, `ready_for_merge = true` (tampil sebagai *Ready for Merge*).
3. Keputusan **Request Changes tersinkron** → `review_state = changes_requested`, `review_status = Changes Requested`, dan backlog kembali `Sedang Dikerjakan` (FR-UC06-14).
4. Backlog menjadi `Selesai` **hanya** saat sinkronisasi GitHub melaporkan PR `merged` (BR-22); approve saja tidak pernah menyelesaikan backlog.
5. Review hanya diterima saat PR masih `Open` (D-W14-01-d). Alur revisi mengikuti BR-18: setelah Request Changes, Developer mengerjakan ulang, memverifikasi push baru, dan mengajukan **PR baru** (head SHA baru) yang kembali berstatus `Open`.

## 4. Endpoint

### 4.1 `GET /pull-requests`

Daftar PR untuk halaman review (FR-UC06-01), terbaru lebih dulu.

Query:

| Nama | Wajib | Validasi | Keterangan |
| --- | --- | --- | --- |
| `status` | tidak | `Open`, `Approved`, `Changes Requested`, `Merged`, `Closed` | Filter `review_status`. Kosong → semua. |
| `page` | tidak | integer ≥ 1 | Default 1. |
| `per_page` | tidak | integer 1–100 | Default 25. |

Respons `200`:

```json
{
  "success": true,
  "message": "Daftar Pull Request.",
  "data": [
    {
      "pull_request_id": 1,
      "issue_id": 1,
      "backlog_id": 1,
      "title": "Perbaikan notifikasi",
      "source_branch": "maintenance/issue-1",
      "target_branch": "main",
      "status": "open",
      "review_status": "Open",
      "review_state": "pending",
      "ready_for_merge": false,
      "status_label": "Open",
      "submission_state": "created",
      "lifecycle_state": "open",
      "developer": { "user_id": 2, "nama": "Developer Uji", "github_login": "dev-gh" },
      "pull_request_number": 42,
      "pull_request_url": "https://github.com/AgilePemdigi-2026/MaintainGov/pull/42",
      "head_sha": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
      "base_sha": "cccccccccccccccccccccccccccccccccccccccc",
      "review_count": 0,
      "last_review": null,
      "created_at": "2026-11-14T08:00:00+00:00",
      "updated_at": "2026-11-14T08:00:00+00:00"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 1 }
}
```

`last_review` berisi ringkasan review terakhir (bentuk sama dengan item `reviews` di §4.2) atau `null`. Endpoint tidak memanggil GitHub.

Error: 401, 403, 422 `VALIDATION_ERROR`.

### 4.2 `GET /pull-requests/{pullRequest}`

Detail PR (FR-UC06-02..04) + daftar review + tautan case/backlog. Bentuk `data` adalah field UC05 §4.3 **ditambah** field berikut (additive; endpoint UC05 lama tidak berubah perilaku):

```json
{
  "success": true,
  "message": "Detail Pull Request.",
  "data": {
    "pull_request_id": 1,
    "issue_id": 1,
    "backlog_id": 1,
    "status": "open",
    "review_status": "Open",
    "review_state": "pending",
    "ready_for_merge": false,
    "status_label": "Open",
    "developer": { "user_id": 2, "nama": "Developer Uji", "github_login": "dev-gh" },
    "repository": { "full_name": "AgilePemdigi-2026/MaintainGov", "owner": "AgilePemdigi-2026", "name": "MaintainGov", "html_url": "https://github.com/AgilePemdigi-2026/MaintainGov" },
    "issue": {
      "backlog_id": 1,
      "title": "Notifikasi tidak muncul",
      "status": "Ready for Pull Request",
      "assigned_to": { "user_id": 2, "nama": "Developer Uji", "github_login": "dev-gh" },
      "case": {
        "case_id": 10,
        "judul_issue": "Notifikasi tidak muncul",
        "kategori": "defect",
        "severity": "High",
        "status_validasi": "Valid",
        "ringkasan_masalah": "Notifikasi update layanan tidak diterima warga."
      }
    },
    "reviews": [
      {
        "review_id": 1,
        "pull_request_id": 1,
        "reviewer": { "user_id": 3, "nama": "Reviewer Uji", "github_login": "reviewer-gh" },
        "decision": "Approved",
        "comment": null,
        "reviewed_at": "2026-11-14T09:00:00+00:00",
        "sync_status": "synced",
        "synced_at": "2026-11-14T09:00:01+00:00",
        "last_sync_error_code": null,
        "github_review_id": 555001,
        "github_review_url": "https://github.com/AgilePemdigi-2026/MaintainGov/pull/42#pullrequestreview-555001",
        "created_at": "2026-11-14T09:00:00+00:00",
        "updated_at": "2026-11-14T09:00:00+00:00"
      }
    ]
  }
}
```

`reviews` diurutkan `reviewed_at` menurun. Satu reviewer maksimal satu review aktif (D-W14-01-c); review baru dari reviewer sama **menggantikan** baris lama.

Error: 401, 403, 404 `PULL_REQUEST_NOT_FOUND`. Tidak memanggil GitHub dan tidak memutasi data.

### 4.3 `POST /pull-requests/{pullRequest}/reviews`

Memberi keputusan review (FR-UC06-07..11, BR-19).

Body:

```json
{
  "decision": "request_changes",
  "comment": "Timeout pengiriman notifikasi belum ditangani; tolong tambahkan retry."
}
```

| Field | Wajib | Validasi |
| --- | --- | --- |
| `decision` | ya | `approve` atau `request_changes`. |
| `comment` | `request_changes` → wajib; `approve` → opsional | string maksimal 5000. |

Aturan (di dalam `DB::transaction`, baris PR dikunci `lockForUpdate`):

1. PR harus ada → 404 `PULL_REQUEST_NOT_FOUND`.
2. Reviewer **tidak boleh penulis PR** (penulis = `pull_request.developer_id`, yaitu assignee backlog saat pengajuan) → 403 `REVIEW_AUTHOR_NOT_ALLOWED` (D-W14-01-a; GitHub juga menolak self-approve).
3. PR harus berstatus `Open` (`submission_state = created`, `lifecycle_state = open`, `review_state = pending`) → selain itu 409 `PULL_REQUEST_NOT_OPEN` (D-W14-01-d).
4. Review disimpan lebih dulu (`sync_status = pending`), menggantikan review sebelumnya dari reviewer yang sama.
5. Keputusan dikirim ke GitHub (`event: APPROVE` / `REQUEST_CHANGES` + body). Hanya respons 200/201 dengan `state` yang valid yang dianggap tersinkron.
6. **Sukses sinkron**: review `sync_status = synced` + `synced_at` + `github_review_id`; transisi status (§3) ditulis dalam transaksi yang sama; Request Changes juga mengembalikan backlog ke `Sedang Dikerjakan`.
7. **Gagal sinkron**: review tetap tersimpan `sync_status = gagal` + `last_sync_error_code`, PR tetap `Open` (keputusan belum tersinkron, FR-UC06-12/13), response tetap 200 dengan `meta.sync` berisi peringatan (D-W14-01-e). Reviewer dapat mengulang submit; upsert menggantikan baris lama.

Respons sukses tersinkron `200`:

```json
{
  "success": true,
  "message": "Review berhasil disinkronkan. Pull Request disetujui; perubahan siap dilanjutkan ke proses merge.",
  "data": {
    "review": { "review_id": 1, "reviewer": { "user_id": 3, "nama": "Reviewer Uji", "github_login": "reviewer-gh" }, "decision": "Approved", "comment": null, "reviewed_at": "2026-11-14T09:00:00+00:00", "sync_status": "synced", "synced_at": "2026-11-14T09:00:01+00:00", "last_sync_error_code": null, "github_review_id": 555001, "github_review_url": "https://github.com/AgilePemdigi-2026/MaintainGov/pull/42#pullrequestreview-555001" },
    "pull_request": { "pull_request_id": 1, "review_status": "Approved", "review_state": "approved", "ready_for_merge": true, "status_label": "Ready for Merge", "status": "open", "lifecycle_state": "open" },
    "backlog": { "backlog_id": 1, "status": "Ready for Pull Request" }
  },
  "meta": { "sync": { "status": "synced" } }
}
```

Pesan `message` per keputusan:

| Keputusan | `message` |
| --- | --- |
| `approve` | `Review berhasil disinkronkan. Pull Request disetujui; perubahan siap dilanjutkan ke proses merge.` |
| `request_changes` | `Perubahan memerlukan perbaikan.` (FR-UC06-15) |

Respons gagal sinkron `200` (peringatan, bukan sukses palsu):

```json
{
  "success": true,
  "message": "Review tersimpan tetapi belum tersinkron ke GitHub. Silakan coba kirim ulang.",
  "data": {
    "review": { "review_id": 1, "decision": "Approved", "sync_status": "gagal", "last_sync_error_code": "GITHUB_UNAVAILABLE", "synced_at": null },
    "pull_request": { "pull_request_id": 1, "review_status": "Open", "review_state": "pending", "ready_for_merge": false, "status_label": "Open" },
    "backlog": { "backlog_id": 1, "status": "Ready for Pull Request" }
  },
  "meta": { "sync": { "status": "gagal", "code": "GITHUB_UNAVAILABLE", "retry_after_seconds": null } }
}
```

Error:

| HTTP | Kode | Kondisi |
| --- | --- | --- |
| 401 | — | Tanpa sesi. |
| 403 | `REVIEW_AUTHOR_NOT_ALLOWED` | Reviewer = penulis PR. |
| 403 | — | Role bukan Developer (middleware). |
| 404 | `PULL_REQUEST_NOT_FOUND` | PR tidak ada. |
| 409 | `PULL_REQUEST_NOT_OPEN` | Status bukan `Open` (belum terbentuk, closed, merged, atau sudah memiliki keputusan tersinkron). |
| 422 | `VALIDATION_ERROR` | `decision` tidak sah / `comment` wajib untuk `request_changes` / komentar > 5000 karakter. |
| 422/429/503 | `GITHUB_VALIDATION_FAILED`, `GITHUB_RATE_LIMITED`, `GITHUB_UNAVAILABLE`, `GITHUB_NOT_CONFIGURED`, `GITHUB_RESOURCE_NOT_FOUND` | Sinkronisasi gagal (lihat perilaku §4.3 langkah 7). |

`GITHUB_RESOURCE_NOT_FOUND` terjadi bila nomor PR tidak ada lagi di GitHub (mis. PR ditutup dan metadata usang).

### 4.4 `GET /maintenance-cases/{case}/history`

Timeline lengkap Case → Backlog → Issue → PR → Review (fitur Status dan Riwayat; FR terkait traceability, BR-23). Role: Tim Maintenance dan Developer (read-only).

Query: tidak ada parameter wajib.

Respons `200`:

```json
{
  "success": true,
  "message": "Riwayat maintenance case.",
  "data": {
    "case": { "case_id": 10, "judul_issue": "Notifikasi tidak muncul", "status_validasi": "Valid", "severity": "High", "kategori": "defect", "modul": "Notifikasi", "fitur": "Push Notifikasi" },
    "timeline": [
      { "type": "case_created", "occurred_at": "2026-11-10T08:00:00+00:00", "actor": null, "detail": { "judul_issue": "Notifikasi tidak muncul" } },
      { "type": "case_validated", "occurred_at": "2026-11-11T09:00:00+00:00", "actor": { "user_id": 1, "nama": "Tim Maintenance Uji", "role": "Tim Maintenance" }, "detail": { "keputusan": "Valid", "validator": "Tim Maintenance Uji" } },
      { "type": "backlog_created", "occurred_at": "2026-11-11T09:00:00+00:00", "actor": { "user_id": 1, "nama": "Tim Maintenance Uji", "role": "Tim Maintenance" }, "detail": { "backlog_id": 1, "status": "Ready for Development" } },
      { "type": "issue_claimed", "occurred_at": "2026-11-12T10:00:00+00:00", "actor": { "user_id": 2, "nama": "Developer Uji", "role": "Developer" }, "detail": { "backlog_id": 1, "source_branch": "maintenance/issue-1" } },
      { "type": "push_detected", "occurred_at": "2026-11-13T08:30:00+00:00", "actor": { "user_id": 2, "nama": "Developer Uji", "role": "Developer" }, "detail": { "branch": "maintenance/issue-1", "commit": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" } },
      { "type": "pull_request_submitted", "occurred_at": "2026-11-14T08:00:00+00:00", "actor": { "user_id": 2, "nama": "Developer Uji", "role": "Developer" }, "detail": { "pull_request_id": 1, "pull_request_number": 42, "pull_request_url": "https://github.com/AgilePemdigi-2026/MaintainGov/pull/42", "title": "Perbaikan notifikasi", "source_branch": "maintenance/issue-1", "target_branch": "main" } },
      { "type": "review_submitted", "occurred_at": "2026-11-14T09:00:00+00:00", "actor": { "user_id": 3, "nama": "Reviewer Uji", "role": "Developer" }, "detail": { "pull_request_id": 1, "review_id": 1, "decision": "Approved", "sync_status": "synced" } }
    ],
    "pull_requests": [ { "pull_request_id": 1, "pull_request_number": 42, "review_status": "Approved", "ready_for_merge": true, "lifecycle_state": "open", "title": "Perbaikan notifikasi" } ]
  }
}
```

Ketentuan timeline:

- `timeline` diurutkan kronologis menaik; tie-break menurut urutan domain (`case_created` → `case_validated` → `backlog_created` → `issue_claimed` → `push_detected` → `pull_request_submitted` → `review_submitted`).
- `actor` `null` berarti sistem/otomatis (mis. case hasil analisis tanpa validator).
- Setiap event PR/review membawa `pull_request_id` agar frontend bisa mengelompokkan per PR (BR-23: rantai tidak diputus).
- Dibangun dengan eager loading tanpa N+1; tidak memanggil GitHub dan tidak memutasi data.

Error: 401, 403, 404 `CASE_NOT_FOUND`.

## 5. Handoff Frontend (Valisha)

Field minimum per halaman:

**Halaman Pull Request (list + detail review):**

| Kebutuhan | Field |
| --- | --- |
| Kartu list | `pull_request_id`, `title`, `review_status`, `status_label`, `developer.nama`, `pull_request_number`, `pull_request_url`, `source_branch`, `target_branch`, `review_count`, `last_review`, `created_at` |
| Badge status | `review_status` (Open/Approved/Changes Requested/Merged/Closed); saat `ready_for_merge = true` tampilkan **Ready for Merge** |
| Detail PR | field §4.2; tab issue memakai `issue.title`, `issue.status`, `issue.case.*` |
| Aksi review | tombol Approve → `POST reviews {decision:"approve"}`; tombol Request Changes → form `comment` wajib (kontrak §4.3); disable aksi saat `review_state !== 'pending'` |
| Notifikasi Request Changes | tampilkan `message` respons persis (`Perubahan memerlukan perbaikan.`) — FR-UC06-15 |
| Peringatan sinkron | `meta.sync.status === 'gagal'` → tampilkan pesan + tombol "Kirim ulang" (submit ulang body yang sama) |
| Filter list | dropdown `?status=` dengan nilai `Open, Approved, Changes Requested, Merged, Closed` |

**Halaman Riwayat (status dan riwayat):**

| Kebutuhan | Field |
| --- | --- |
| Header | `case.case_id`, `case.judul_issue`, `case.status_validasi`, `case.severity` |
| Timeline | `type`, `occurred_at`, `actor.nama`, `actor.role`, `detail` (bentuk per `type` sesuai §4.4) |
| Label event | peta `type` → label Indonesia: `case_created` "Case dibuat", `case_validated` "Case divalidasi", `backlog_created` "Backlog dibentuk", `issue_claimed` "Issue diambil", `push_detected` "Push terdeteksi", `pull_request_submitted` "Pull Request diajukan", `review_submitted` "Review diberikan" |
| Pengelompokan PR | `pull_requests[]` + `detail.pull_request_id` pada tiap event |
| Badge review | `detail.decision` (Approved / Changes Requested) + `detail.sync_status` (`synced`/`pending`/`gagal`) |

Batas yang perlu dikomunikasikan di UI: `sync_status` selain `synced` berarti keputusan **belum** berlaku; PR dengan `review_status` selain `Open` tidak menerima review baru; status backlog `Selesai` hanya muncul setelah PR `Merged` tersinkron.

## 6. Batas verifikasi

- Seluruh endpoint diuji PHPUnit dengan `Http::fake` terhadap GitHub palsu (pola UC05).
- Live GitHub smoke untuk review BLOCKED (token belum tersedia) — tidak ada klaim PASS live.
- Sinkronisasi `merged` (FR-UC06-16) belum diimplementasikan; kontraknya menyusul bersama webhook PR.
- Route mock `/developer/github-mock/*` tidak menyediakan stub UC06; frontend memakai endpoint nyata ini untuk QA alur review.
