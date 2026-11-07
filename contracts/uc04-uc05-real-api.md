# Kontrak API Nyata — UC04 dan UC05

Versi: 1.0 (W13-01, 4 November 2026)<br>
Status: **endpoint UC04/UC05 nyata dan persisten**. Pull Request dibuat melalui GitHub REST API, bukan simulasi.<br>
Branch kerja: `feature/W13-01-uc04-uc05-backend`<br>
Acuan: SRS §4.6 UC04 (FR-UC04-01..11, BR-13..15), §4.7 UC05 (FR-UC05-01..14, BR-16..18), dan [arsitektur](../docs/ARCHITECTURE.md).

> Dokumen ini **melengkapi**, bukan mengganti, [`uc04-uc05-api.md`](uc04-uc05-api.md). Kontrak Week 1 dan route mock `/developer/github-mock/*` tetap berlaku untuk pengembangan frontend/QA. Route nyata memakai namespace final `/maintenance-backlogs/*` dan `/developer/maintenance-issues`.

## 1. Ruang lingkup

| Area | Endpoint nyata | Memanggil GitHub | Menulis database |
| --- | --- | --- | --- |
| Daftar/detail issue UC04 | `GET /api/v1/developer/maintenance-issues[/{issue}]` | tidak | tidak |
| Klaim issue | `POST /api/v1/maintenance-backlogs/{issue}/claim` | tidak | ya |
| Status push | `GET /api/v1/maintenance-backlogs/{issue}/push-status` | tidak | tidak |
| Verifikasi push manual | `POST /api/v1/maintenance-backlogs/{issue}/push-verifications` | ya, baca branch + commit | ya |
| Webhook push GitHub | `POST /api/v1/webhooks/github` | ya, baca branch + commit | ya |
| Pilihan branch | `GET /api/v1/maintenance-backlogs/{issue}/branches` | ya, baca branch | tidak |
| Daftar/detail PR | `GET /api/v1/maintenance-backlogs/{issue}/pull-requests`, `GET /api/v1/pull-requests/{pullRequest}` | tidak | tidak |
| Buat PR nyata | `POST /api/v1/maintenance-backlogs/{issue}/pull-requests` | ya, `POST /repos/{owner}/{repo}/pulls` | ya |
| Rekonsiliasi PR | `POST /api/v1/maintenance-backlogs/{issue}/pull-requests/reconcile` | ya, cari PR | ya |

Di luar ruang lingkup: review/approve/merge (UC06), sinkronisasi status PR berkala, GitHub App JWT, dan panel log teknis.

## 2. Konvensi umum

- Base URL: `/api/v1`.
- Autentikasi browser: session cookie Laravel. Request `POST` wajib menyertakan `X-CSRF-TOKEN` dari `GET /api/v1/auth/csrf`, kecuali webhook GitHub.
- Role semua endpoint UC04/UC05: `Developer`. Endpoint koneksi `GET /github/connection` tetap tersedia untuk `Tim Maintenance` dan `Developer`.
- `{issue}` adalah `maintenance_backlog.backlog_id` (integer positif). `{pullRequest}` adalah `pull_request.pull_request_id`.
- Waktu respons memakai ISO 8601. Database menyimpan waktu dalam UTC.
- Token GitHub tidak pernah dikirim ke browser, tidak dicatat di log, dan tidak ada di respons.
- `owner`/`repository` selalu berasal dari konfigurasi server, bukan input browser.

### 2.1 Envelope sukses

```json
{
  "success": true,
  "message": "Pesan manusiawi.",
  "data": {},
  "meta": {}
}
```

`message`, `data`, dan `meta` dapat berbeda per endpoint. `meta` hanya ada bila relevan.

### 2.2 Envelope error domain

```json
{
  "success": false,
  "message": "Pesan manusiawi.",
  "error": {
    "code": "KODE_STABIL",
    "message": "Pesan manusiawi."
  },
  "data": null
}
```

- `error.code` adalah kontrak mesin; frontend tidak boleh mencocokkan teks pesan.
- `error.retry_after_seconds` dan header `Retry-After` hanya ada pada rate limit yang menyediakan nilai.
- `data` dapat berisi konteks aman, misalnya `expected_source_branch` atau `branch_head_sha`.

### 2.3 Error autentikasi, role, validasi, dan route

| HTTP | Bentuk | Kondisi |
| --- | --- | --- |
| 401 | `{success:false,message:"Akses ditolak. Silakan login terlebih dahulu."}` | Tanpa sesi pada route ber-role. |
| 403 | `{success:false,message:"Akses ditolak. Role tidak memiliki izin."}` | Role bukan `Developer`. |
| 422 | envelope `VALIDATION_ERROR` + `errors` | Validasi FormRequest gagal. |
| 404 | `{success:false,message:"Endpoint tidak ditemukan.",data:null}` | Route tidak ada. |
| 500 | `{success:false,message:"Terjadi kesalahan pada database.",data:null}` | `QueryException` yang tidak tertangani. |

Validasi gagal:

```json
{
  "success": false,
  "message": "Data yang diberikan tidak valid.",
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Data yang diberikan tidak valid."
  },
  "errors": {
    "source_branch": ["Format branch sumber tidak valid."]
  }
}
```

## 3. Endpoint UC04

### 3.1 `GET /developer/maintenance-issues`

Daftar Maintenance Issue untuk Developer.

Query:

| Nama | Wajib | Validasi | Keterangan |
| --- | --- | --- | --- |
| `status` | tidak | `Ready for Development`, `Sedang Dikerjakan`, `Ready for Pull Request`, `Selesai` | Filter status. |
| `assigned_to_me` | tidak | boolean (`1`, `0`, `true`, `false`) | Hanya issue yang ditugaskan ke user login. |
| `page` | tidak | integer ≥ 1 | Default 1. |
| `per_page` | tidak | integer 1–100 | Default 25. |

Urutan: severity `High` → `Medium` → `Low`, lalu `priority_score` menurun, lalu `backlog_id` naik.

Respons `200`:

```json
{
  "success": true,
  "message": "Daftar Maintenance Issue.",
  "data": [
    {
      "issue_id": 1,
      "backlog_id": 1,
      "case_id": 10,
      "title": "Notifikasi tidak muncul",
      "summary": "Notifikasi update layanan tidak diterima warga.",
      "kategori": "defect",
      "module": "Notifikasi",
      "fitur": "Push Notifikasi",
      "priority": "High",
      "severity": "High",
      "priority_score": 9.0,
      "status": "Ready for Development",
      "assigned_to": null,
      "assigned_at": null,
      "source_branch": null,
      "push_status": "not_detected",
      "last_push": null,
      "repository": {
        "owner": "AgilePemdigi-2026",
        "name": "MaintainGov",
        "full_name": "AgilePemdigi-2026/MaintainGov",
        "default_branch": null,
        "html_url": null
      },
      "created_at": "2026-11-04T10:00:00+00:00",
      "updated_at": "2026-11-04T10:00:00+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 25,
    "total": 1
  }
}
```

`push_status` bernilai `detected` bila `last_push_sha` terisi, selain itu `not_detected`. `last_push` berisi `branch`, `commit`, dan `pushed_at`. Endpoint ini **tidak pernah** memutasi data.

Error: 401, 403, 422.

### 3.2 `GET /developer/maintenance-issues/{issue}`

Detail satu issue dengan bentuk data yang sama seperti item daftar.

Error: 401, 403, 404 `ISSUE_NOT_FOUND`. Endpoint ini tidak memanggil GitHub dan tidak memutasi data.

### 3.3 `POST /maintenance-backlogs/{issue}/claim`

Klaim issue secara atomik.

Body opsional:

```json
{
  "source_branch": "fix/notification-timeout"
}
```

| Field | Wajib | Validasi |
| --- | --- | --- |
| `source_branch` | tidak | string max 255; regex Git branch aman; tidak boleh default branch; tidak boleh dipakai issue aktif lain. |

Bila `source_branch` tidak dikirim, backend memakai `maintenance/issue-{issue}`.

Aturan:

1. Issue harus ada.
2. `assigned_to` harus `null`.
3. Status harus `Ready for Development`.
4. `source_branch` tidak boleh sama dengan `GITHUB_DEFAULT_BRANCH`.
5. `source_branch` tidak boleh dipakai issue lain berstatus `Sedang Dikerjakan` atau `Ready for Pull Request`.
6. Repository trusted disimpan/ditautkan ke backlog. Klaim **tidak** memanggil GitHub.
7. Dalam satu transaksi, baris dikunci (`lockForUpdate`) dan di-update bersyarat. Hanya satu pemanggil boleh menang.

Respons `200` berisi objek issue lengkap dengan `message` tambahan:

```json
{
  "success": true,
  "message": "Issue berhasil diambil.",
  "data": {
    "issue_id": 1,
    "status": "Sedang Dikerjakan",
    "assigned_to": {
      "user_id": 2,
      "nama": "Developer Uji",
      "github_login": "dev-gh"
    },
    "assigned_at": "2026-11-04T10:00:00+00:00",
    "source_branch": "fix/notification-timeout",
    "message": "Issue berhasil diambil."
  }
}
```

Error:

| HTTP | Kode | Kondisi |
| --- | --- | --- |
| 404 | `ISSUE_NOT_FOUND` | Issue tidak ada. |
| 409 | `ISSUE_ALREADY_ASSIGNED` | Sudah diambil Developer lain; pesan persis “Issue telah diambil Developer lain”. |
| 409 | `ISSUE_NOT_CLAIMABLE` | Status bukan `Ready for Development`. |
| 409 | `SOURCE_BRANCH_IN_USE` | Branch dipakai issue aktif lain. |
| 422 | `SOURCE_BRANCH_PROTECTED` | Branch sama dengan default branch. |
| 422 | `VALIDATION_ERROR` | Format branch tidak valid. |
| 503 | `GITHUB_NOT_CONFIGURED` | Owner/repository server belum diisi; tidak ada assignment. |

### 3.4 `GET /maintenance-backlogs/{issue}/push-status`

Status push read-only.

```json
{
  "success": true,
  "message": "Status push Maintenance Issue.",
  "data": {
    "issue_id": 1,
    "status": "detected",
    "issue_status": "Ready for Pull Request",
    "branch": "fix/notification-timeout",
    "last_push": {
      "branch": "fix/notification-timeout",
      "commit": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
      "pushed_at": "2026-11-04T09:30:00+00:00"
    },
    "verified": true
  }
}
```

`verified=true` hanya bila push terdeteksi **dan** issue berstatus `Ready for Pull Request`. Endpoint tidak memanggil GitHub dan tidak memutasi data.

Error: 401, 403, 404 `ISSUE_NOT_FOUND`.

### 3.5 `POST /maintenance-backlogs/{issue}/push-verifications`

Verifikasi push manual oleh assignee. Backend membaca branch head dan commit langsung dari GitHub; SHA dari browser bukan bukti.

Body opsional:

```json
{
  "commit_sha": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
}
```

`commit_sha` opsional, 40 karakter heksadesimal. Bila dikirim dan berbeda dari head branch GitHub → `409 PUSH_SHA_MISMATCH`.

Aturan:

- Hanya assignee yang boleh memverifikasi.
- Status harus `Sedang Dikerjakan` atau `Ready for Pull Request`.
- `source_branch` harus sudah terisi.
- `GET /repos/{owner}/{repo}/branches/{branch}` harus mengembalikan nama branch yang sama.
- `GET /repos/{owner}/{repo}/commits/{sha}` harus mengembalikan SHA yang sama.
- `last_push_sha` disimpan dari SHA GitHub, `last_push_at` dari timestamp commit GitHub.
- Transisi `Sedang Dikerjakan` → `Ready for Pull Request` hanya terjadi setelah verifikasi berhasil. Verifikasi ulang saat sudah `Ready for Pull Request` tidak menurunkan status.

Respons `200`:

```json
{
  "success": true,
  "message": "Push berhasil diverifikasi.",
  "data": {
    "issue": {
      "issue_id": 1,
      "status": "Ready for Pull Request",
      "last_push": {
        "branch": "fix/notification-timeout",
        "commit": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
        "pushed_at": "2026-11-04T09:30:00+00:00"
      }
    },
    "verification": {
      "branch": "fix/notification-timeout",
      "commit_sha": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
      "commit_url": "https://github.com/AgilePemdigi-2026/MaintainGov/commit/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
      "verified_at": "2026-11-04T10:00:00+00:00",
      "source": "github_api"
    }
  }
}
```

Error umum: 401, 403 `ISSUE_NOT_ASSIGNED_TO_DEVELOPER`, 404 `ISSUE_NOT_FOUND`, 409 `ISSUE_NOT_ASSIGNED`/`ISSUE_NOT_VERIFIABLE`/`ISSUE_BRANCH_NOT_SET`/`PUSH_SHA_MISMATCH`, 422 `SOURCE_BRANCH_NOT_FOUND`/`PUSH_COMMIT_NOT_FOUND`, 503 `GITHUB_NOT_CONFIGURED`/`GITHUB_UNAVAILABLE`, 429 `GITHUB_RATE_LIMITED`.

## 4. Endpoint UC05

### 4.1 `GET /maintenance-backlogs/{issue}/branches`

Pilihan branch untuk formulir PR. Hanya assignee dan hanya saat issue `Ready for Pull Request`.

Query: `per_page` integer 1–100, default 100.

Respons `200`:

```json
{
  "success": true,
  "message": "Daftar branch repository.",
  "data": {
    "issue_id": 1,
    "source_branch": "fix/notification-timeout",
    "suggested_target_branch": "main",
    "default_branch": "main",
    "repository": {
      "owner": "maintaingov",
      "name": "app",
      "full_name": "maintaingov/app",
      "default_branch": "main",
      "html_url": "https://github.com/maintaingov/app"
    },
    "branches": [
      {
        "name": "main",
        "sha": "cccccccccccccccccccccccccccccccccccccccc",
        "protected": true
      }
    ]
  }
}
```

Backend memverifikasi branch sumber masih ada di GitHub sebelum menampilkan daftar. Error: 401, 403, 404 `ISSUE_NOT_FOUND`, 409 `ISSUE_NOT_READY_FOR_PULL_REQUEST`, 422 `SOURCE_BRANCH_NOT_FOUND`, 503/429 error GitHub.

### 4.2 `GET /maintenance-backlogs/{issue}/pull-requests`

Daftar PR yang tersimpan untuk issue, terbaru lebih dulu.

```json
{
  "success": true,
  "message": "Daftar Pull Request untuk Maintenance Issue.",
  "data": [],
  "meta": {
    "issue_id": 1,
    "total": 0
  }
}
```

Error: 401, 403, 404 `ISSUE_NOT_FOUND`.

### 4.3 `GET /pull-requests/{pullRequest}`

Detail satu PR tersimpan. Bentuk `data` sama dengan item daftar.

Field inti:

| Field | Keterangan |
| --- | --- |
| `pull_request_id` | ID internal. |
| `issue_id`/`backlog_id` | Backlog sumber. |
| `developer` | `user_id`, `nama`, `github_login`. |
| `repository` | `full_name`, `owner`, `name`, `html_url`. |
| `source_branch`, `target_branch`, `title`, `description` | Metadata pengajuan. |
| `github_pr_id`, `github_pr_number`, `github_pr_url` | Metadata PR nyata; `null` sampai GitHub sukses. |
| `status` | `lifecycle_state` bila ada, selain itu `submission_state`. |
| `submission_state` | `pending`, `created`, `failed`, `reconciliation_required`. |
| `lifecycle_state` | `open`, `closed`, `merged`, atau `null`. |
| `head_sha`, `base_sha` | SHA GitHub saat metadata disimpan. |
| `last_error_code` | Kode kegagalan terakhir, bila ada. |
| `reconciled_at` | Waktu rekonsiliasi berhasil. |

Error: 401, 403, 404 `PULL_REQUEST_NOT_FOUND`.

### 4.4 `POST /maintenance-backlogs/{issue}/pull-requests`

Membuat Pull Request **nyata** di GitHub setelah seluruh validasi berhasil.

Header opsional:

```http
Idempotency-Key: idem-uc05-2026-11-04-001
```

Header ini dianggap sama dengan field body `idempotency_key`. Body:

```json
{
  "source_branch": "fix/notification-timeout",
  "target_branch": "main",
  "title": "Perbaikan notifikasi",
  "description": "Menambahkan timeout dan retry pada pengiriman notifikasi.",
  "idempotency_key": "idem-uc05-2026-11-04-001"
}
```

| Field | Wajib | Validasi |
| --- | --- | --- |
| `source_branch` | ya | string max 255, format branch aman, harus sama dengan `source_branch` issue. |
| `target_branch` | ya | string max 255, format branch aman, harus berbeda dari source. |
| `title` | ya | string max 255. |
| `description` | ya | string max 5000. |
| `idempotency_key` | tidak | string max 255; `A-Z`, `a-z`, `0-9`, `.`, `_`, `:`, `-`. |

Prasyarat backend:

1. Pemanggil adalah assignee issue.
2. Status issue `Ready for Pull Request`.
3. `source_branch` issue terisi dan sama dengan body.
4. `last_push_sha` terverifikasi tersedia.
5. Koneksi GitHub berhasil diperiksa ulang (`GET /repos/{owner}/{repo}`).
6. Repository trusted disinkronkan; source/target branch ada di GitHub.
7. Head source branch masih sama dengan `last_push_sha`; bila berubah → `409 SOURCE_BRANCH_CHANGED`.
8. Commit head ada di GitHub.
9. Tidak ada PR dengan head/base yang sama. Bila ada, metadata direkonsiliasi, bukan membuat PR baru.

Alur efek samping:

1. Baris `pull_request` `pending` dengan fingerprint unik disimpan lebih dulu.
2. GitHub dipanggil: `POST /repos/{owner}/{repo}/pulls` dengan `title`, `head`, `base`, `body`.
3. Hanya respons `200/201` dengan `state="open"` yang diterima.
4. Metadata (`github_pr_id`, `github_pr_number`, `github_pr_url`, `head_sha`, `base_sha`, `lifecycle_state=open`) disimpan secara transaksional.
5. Notifikasi sukses hanya boleh muncul setelah langkah 4 berhasil.

Respons sukses pertama: HTTP **201**

```json
{
  "success": true,
  "message": "Pull Request berhasil dibuat.",
  "data": {
    "pull_request_id": 1,
    "issue_id": 1,
    "github_pr_id": 98765,
    "github_pr_number": 42,
    "github_pr_url": "https://github.com/AgilePemdigi-2026/MaintainGov/pull/42",
    "pull_request_number": 42,
    "pull_request_url": "https://github.com/AgilePemdigi-2026/MaintainGov/pull/42",
    "status": "open",
    "lifecycle_state": "open",
    "submission_state": "created",
    "head_sha": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "base_sha": "cccccccccccccccccccccccccccccccccccccccc"
  },
  "meta": {
    "idempotent_replay": false,
    "reconciled": false
  }
}
```

Replay idempoten: HTTP **200**, `meta.idempotent_replay=true`, PR yang sama. Bila PR ditemukan di GitHub setelah timeout/kegagalan DB: HTTP **200**, `meta.idempotent_replay=true`, `meta.reconciled=true`, `message` menyebut rekonsiliasi.

Error:

| HTTP | Kode | Kondisi |
| --- | --- | --- |
| 403 | `ISSUE_NOT_ASSIGNED_TO_DEVELOPER` | Bukan assignee. |
| 404 | `ISSUE_NOT_FOUND` | Issue tidak ada. |
| 409 | `ISSUE_NOT_READY_FOR_PULL_REQUEST` | Status belum siap PR. |
| 409 | `ISSUE_BRANCH_NOT_SET` | Branch sumber issue kosong. |
| 409 | `PUSH_NOT_VERIFIED` | Belum ada push terverifikasi. |
| 409 | `SOURCE_BRANCH_CHANGED` | Head branch berubah; verifikasi ulang. |
| 409 | `PR_SUBMISSION_IN_PROGRESS` | Baris pending masih segar; tunggu. |
| 409 | `IDEMPOTENCY_KEY_CONFLICT` | Key sudah dipakai pengaju lain. |
| 422 | `SOURCE_BRANCH_MISMATCH` | Body source ≠ branch issue. |
| 422 | `SOURCE_BRANCH_NOT_FOUND` | Branch sumber tidak ada di GitHub. |
| 422 | `TARGET_BRANCH_NOT_FOUND` | Branch tujuan tidak ada di GitHub. |
| 422 | `PUSH_COMMIT_NOT_FOUND` | Commit head tidak ada. |
| 422 | `GITHUB_VALIDATION_FAILED` | GitHub menolak dan tidak ada PR yang bisa direkonsiliasi. |
| 422 | `VALIDATION_ERROR` | Validasi body gagal. |
| 429 | `GITHUB_RATE_LIMITED` | Rate limit; lihat `retry_after_seconds`. |
| 503 | `GITHUB_NOT_CONFIGURED`/`GITHUB_UNAVAILABLE`/`GITHUB_INVALID_RESPONSE` | GitHub tidak dapat dipakai. |
| 500 | `PR_RECONCILIATION_REQUIRED` | PR mungkin sudah dibuat, metadata belum tersimpan; jalankan rekonsiliasi. |

Kegagalan GitHub **tidak pernah** dilaporkan sebagai sukses dan tidak mengubah status issue menjadi `Selesai`. Baris `pull_request` dapat berstatus `failed` untuk audit/retry.

### 4.5 `POST /maintenance-backlogs/{issue}/pull-requests/reconcile`

Mencari PR yang sudah ada di GitHub untuk baris `pending`, `failed`, atau `reconciliation_required`, lalu menyimpan metadata bila ditemukan. Endpoint ini **tidak membuat PR baru**.

Respons `200`:

```json
{
  "success": true,
  "message": "Metadata Pull Request berhasil direkonsiliasi.",
  "data": {
    "status": "reconciled",
    "records": [
      {
        "pull_request_id": 1,
        "status": "reconciled",
        "github_pr_number": 42,
        "github_pr_url": "https://github.com/AgilePemdigi-2026/MaintainGov/pull/42",
        "data": {}
      }
    ]
  }
}
```

`data.status`:

- `reconciled`: minimal satu baris berhasil ditemukan dan disimpan.
- `not_found`: tidak ada PR yang cocok; baris tetap seperti semula.
- `nothing_to_reconcile`: tidak ada kandidat.

`records[].status`: `reconciled`, `in_progress`, atau `not_found`.

Error: 401, 403 `ISSUE_NOT_ASSIGNED_TO_DEVELOPER`, 404 `ISSUE_NOT_FOUND`, 429/503 error GitHub.

### 4.6 Operasi rekonsiliasi

```bash
cd apps/api
php artisan github:reconcile-pull-requests --dry-run
php artisan github:reconcile-pull-requests --issue=1
```

- Perintah tidak pernah membuat PR.
- `--dry-run` hanya menampilkan kandidat dan tidak memanggil GitHub.
- Kandidat: `submission_state` ∈ `pending`, `failed`, `reconciliation_required`.
- Hasil `not_found` bukan alasan untuk membuat PR baru secara otomatis.

## 5. Webhook GitHub

### 5.1 `POST /webhooks/github`

Endpoint ini dipanggil GitHub, bukan browser. Tidak memakai session/CSRF. Autentikasi adalah signature HMAC.

Header wajib:

| Header | Keterangan |
| --- | --- |
| `X-Hub-Signature-256` | `sha256=` + HMAC-SHA256(raw body, `GITHUB_WEBHOOK_SECRET`). |
| `X-GitHub-Event` | Minimal `push`; `ping` didukung. |
| `X-GitHub-Delivery` | ID unik, maksimal 100 karakter. |

Signature dihitung dari **raw body persis** dan dibandingkan dengan `hash_equals`. Bila `GITHUB_WEBHOOK_SECRET` kosong → 503, payload unsigned tidak pernah diterima.

### 5.2 Urutan pemeriksaan

1. Signature.
2. Event dan delivery ID.
3. JSON payload.
4. Ledger delivery persisten (deduplikasi/replay).
5. Event `ping` → `pong`.
6. Event selain `push` → `202 ignored`, `reason=unsupported_event`.
7. Repository harus sama persis dengan `GITHUB_REPOSITORY_OWNER/GITHUB_REPOSITORY_NAME`.
8. Ref harus `refs/heads/*`; tag/ref lain → `202 ignored`.
9. `after` harus SHA 40 karakter dan bukan nol (branch deletion) → `202 ignored`.
10. Timestamp push harus lebih baru dari `GITHUB_WEBHOOK_MAX_AGE`; terlalu tua → `409 WEBHOOK_STALE_DELIVERY`.
11. Cari issue aktif dengan `source_branch` tersebut; tidak ada → `202 no_matching_issue`.
12. Assignee harus punya `github_login`; aktor (`sender.login`/`pusher.name`) harus cocok.
13. GitHub branch head harus sama dengan `after`. Bila sudah disusul push lebih baru → `202 ignored`, `superseded_by_newer_push`.
14. Commit harus ada di GitHub.
15. Transisi `Sedang Dikerjakan` → `Ready for Pull Request` dan simpan `last_push_sha`/`last_push_at` dalam transaksi.
16. Ledger ditandai `processed`.

### 5.3 Respons

| HTTP | `data.status` | Kapan |
| --- | --- | --- |
| 200 | `processed` | Push terverifikasi; issue diperbarui. |
| 200 | `duplicate` | Delivery ID sama dan payload sama; tidak ada pemrosesan ulang. |
| 200 | `pong` | `ping` bertanda tangan valid. |
| 202 | `ignored` | Event/ref/branch/issue/aktor tidak relevan; tidak mengubah status. |
| 202 | `in_progress` | Delivery yang sama sedang diproses. |

Contoh sukses:

```json
{
  "success": true,
  "message": "Push berhasil diverifikasi. Issue siap diajukan Pull Request.",
  "data": {
    "status": "processed",
    "delivery_id": "72f...",
    "issue": {
      "issue_id": 1,
      "status": "Ready for Pull Request",
      "last_push": {
        "branch": "maintenance/issue-1",
        "commit": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
        "pushed_at": "2026-11-04T09:30:00+00:00"
      }
    }
  }
}
```

Error webhook:

| HTTP | Kode |
| --- | --- |
| 400 | `WEBHOOK_EVENT_REQUIRED`, `WEBHOOK_DELIVERY_ID_INVALID`, `WEBHOOK_INVALID_PAYLOAD`, `WEBHOOK_INVALID_TIMESTAMP` |
| 401 | `WEBHOOK_SIGNATURE_INVALID` |
| 403 | `WEBHOOK_REPOSITORY_MISMATCH`, `WEBHOOK_ASSIGNEE_UNMAPPED`, `WEBHOOK_ACTOR_MISSING`, `WEBHOOK_ACTOR_MISMATCH` |
| 409 | `WEBHOOK_DELIVERY_CONFLICT`, `WEBHOOK_STALE_DELIVERY`, `WEBHOOK_BRANCH_NOT_FOUND`, `WEBHOOK_COMMIT_INVALID` |
| 429 | `GITHUB_RATE_LIMITED` |
| 503 | `WEBHOOK_NOT_CONFIGURED`, `GITHUB_NOT_CONFIGURED`, `GITHUB_UNAVAILABLE`, `GITHUB_INVALID_RESPONSE` |
| 500 | `WEBHOOK_PROCESSING_FAILED` |

### 5.4 Deduplikasi, replay, dan retry

- `github_webhook_delivery.github_delivery_id` unik.
- Delivery `processed`/`ignored` yang dikirim ulang → `200 duplicate`; payload berbeda dengan ID sama → `409 WEBHOOK_DELIVERY_CONFLICT`.
- Delivery `failed` boleh di-retry dengan ID yang sama; baris ledger dipakai ulang.
- Delivery `received` lebih dari 300 detik dianggap stale dan boleh diproses ulang.
- Event yang lebih tua dari `GITHUB_WEBHOOK_MAX_AGE` ditolak; replay lama tidak dapat menurunkan status.

## 6. Idempotensi dan rekonsiliasi UC05

### 6.1 Kunci idempotensi

- `request_fingerprint` = SHA-256 dari `backlog_id`, `developer_id`, branch sumber, branch tujuan, judul/deskripsi yang di-trim, dan head SHA. Unik.
- `idempotency_key` opsional, unik per backlog.
- Tuple `(backlog_id, source_branch, target_branch, github_head_sha)` unik.
- Baris `pending` dicadangkan sebelum GitHub dipanggil; dua request paralel tidak dapat memanggil GitHub dua kali untuk tuple yang sama.

### 6.2 Perilaku replay

| Kondisi baris | Perilaku |
| --- | --- |
| `created` + `github_pr_number` ada | `200`, kembalikan metadata yang sama, tidak memanggil GitHub. |
| `pending` lebih muda dari `GITHUB_PR_STALE_SECONDS` | `409 PR_SUBMISSION_IN_PROGRESS`. |
| `pending`/`failed` dan PR ditemukan di GitHub | `200`, `reconciled=true`, metadata disimpan. |
| `pending`/`failed` dan PR tidak ditemukan, sudah stale | Retry aman memakai baris yang sama. |
| `reconciliation_required` | `POST .../reconcile` atau command; tidak membuat PR baru. |

### 6.3 Kegagalan setelah GitHub sukses

Bila `POST /pulls` sukses tetapi penyimpanan DB gagal:

1. Backend mencari PR berdasarkan head/base.
2. Bila ditemukan, metadata disimpan dan respons `200 reconciled` — bukan klaim sukses palsu dan bukan PR kedua.
3. Bila tidak dapat dipastikan, baris ditandai `reconciliation_required` dan API mengembalikan `500 PR_RECONCILIATION_REQUIRED` dengan `data.pull_request_id`.
4. Rekonsiliasi manual/operasional wajib dijalankan sebelum retry.

## 7. Pemetaan error GitHub

| Kondisi GitHub | HTTP | Kode internal |
| --- | --- | --- |
| 401 | 401 | `GITHUB_UNAUTHORIZED` |
| 403 bukan rate limit | 403 | `GITHUB_FORBIDDEN` |
| 403 rate limit / 429 | 429 | `GITHUB_RATE_LIMITED` + `retry_after_seconds` bila ada |
| 404 | 404/422 sesuai konteks | `GITHUB_RESOURCE_NOT_FOUND`, lalu `SOURCE_BRANCH_NOT_FOUND`/`TARGET_BRANCH_NOT_FOUND`/`PUSH_COMMIT_NOT_FOUND` |
| 422 | 422 | `GITHUB_VALIDATION_FAILED` |
| 5xx, timeout, jaringan | 503 | `GITHUB_UNAVAILABLE` |
| Payload tidak sesuai kontrak | 503 | `GITHUB_INVALID_RESPONSE` |
| Konfigurasi kosong | 503 | `GITHUB_NOT_CONFIGURED` |

Body/header mentah GitHub dan token tidak pernah diteruskan ke client.

## 8. Konfigurasi (nama variabel saja)

- `GITHUB_API_URL`
- `GITHUB_REPOSITORY_OWNER`
- `GITHUB_REPOSITORY_NAME`
- `GITHUB_DEFAULT_BRANCH`
- `GITHUB_TOKEN`
- `GITHUB_TIMEOUT`
- `GITHUB_CONNECT_TIMEOUT`
- `GITHUB_WEBHOOK_SECRET`
- `GITHUB_WEBHOOK_MAX_AGE`
- `GITHUB_PR_STALE_SECONDS`
- `GITHUB_MOCK_ENABLED` (hanya route mock Week 1)

Nilai secret tidak boleh masuk repository, respons API, log, atau bundle frontend.

## 9. Batas verifikasi

- Seluruh kontrak di atas diuji dengan PHPUnit + `Http::fake` terhadap GitHub palsu.
- **Live GitHub authenticated smoke BLOCKED** karena `GITHUB_TOKEN` belum tersedia. Tidak ada hasil PASS live yang diklaim.
- Webhook nyata membutuhkan secret dan URL publik; pengujian otomatis memakai signature HMAC yang dibuat test.
- Route mock `/developer/github-mock/*` tetap terpisah dan tidak boleh dianggap sebagai bukti PR nyata.
