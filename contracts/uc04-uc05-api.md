# Kontrak API — Koneksi GitHub, UC04 dan UC05

Versi: draf v0.1 (Feature 9 / Sprint 2 Week 1)  
Status: endpoint koneksi **nyata read-only**; endpoint UC04–UC05 **mock/stub** untuk frontend dan QA.  
Acuan resmi: SRS §4.6 UC04 (FR-UC04-01..11, BR-13..15) dan §4.7 UC05 (FR-UC05-01..14, BR-16..18).

> **Batas penting:** mock tidak melakukan push, tidak membuat PR, tidak mengubah database, dan tidak memanggil GitHub. Semua respons mock memiliki header `X-Mock-Data: true` dan `meta.is_mock=true`. Jangan menampilkan `github_pr_number`/`github_pr_url` sebagai tautan nyata karena nilainya `null` pada stub.

## 1. Konvensi umum

- Base URL: `/api/v1`.
- Autentikasi: session cookie Laravel. Request `POST` membutuhkan token CSRF dari `GET /api/v1/auth/csrf`, dikirim sebagai header `X-CSRF-TOKEN`.
- Role:
  - Koneksi GitHub: `Tim Maintenance` atau `Developer`.
  - Mock UC04–UC05: hanya `Developer`, sesuai aktor SRS.
- Format sukses: `{ "success": true, "data": ..., "meta": ... }`.
- Format error endpoint GitHub/mock: `{ "success": false, "message": "...", "error": { "code": "...", "message": "..." } }`.
- Error middleware/framework mengikuti perilaku aplikasi saat ini:
  - Tanpa sesi: HTTP 401 `{ "message": "Unauthenticated." }`.
  - Role salah: HTTP 403 `{ "success": false, "message": "Akses ditolak. Role tidak memiliki izin." }`.
  - Validasi: HTTP 422 `{ "message": "...", "errors": { "field": ["..."] } }`.
- Status issue memakai istilah SRS: `Ready for Development`, `Sedang Dikerjakan`, `Ready for Pull Request`, `Ready for Merge`.
- Waktu pada fixture memakai UTC (`+00:00`) dan data fiktif; jangan menganggapnya data produksi.

### Implementasi

| Area | Implementasi | Memanggil GitHub? | Menyimpan DB? |
| --- | --- | --- | --- |
| `GET /github/connection` | nyata, read-only | ya, `GET /repos/{owner}/{repo}` | tidak |
| `/developer/github-mock/issues*` | mock/stub deterministik | tidak | tidak |
| `POST /developer/github-mock/issues/{id}/pull-requests` | stub persiapan PR | tidak | tidak |
| Push dari Developer | di luar MaintainGov | ya, oleh Developer | belum |

### Fixture

Semua contoh lengkap ada di [`contracts/fixtures/uc04-uc05/`](fixtures/uc04-uc05/).

---

## 2. `GET /github/connection`

**Tujuan:** memeriksa apakah Laravel dapat membaca repository utama memakai credential server-side.

**Authentication/authorization:** sesi aktif; role `Tim Maintenance` atau `Developer`.

**Parameter:** tidak ada.

**Contoh request:**

```http
GET /api/v1/github/connection
Accept: application/json
Cookie: <session>
```

**Sukses HTTP 200:**

```json
{
  "success": true,
  "data": {
    "status": "connected",
    "repository": {
      "owner": "AgilePemdigi-2026",
      "name": "MaintainGov",
      "full_name": "AgilePemdigi-2026/MaintainGov",
      "default_branch": "main",
      "html_url": "https://github.com/AgilePemdigi-2026/MaintainGov",
      "private": false,
      "permissions": null
    },
    "checked_at": "2026-11-01T12:33:20+00:00"
  }
}
```

`permissions` berisi objek boolean hanya jika GitHub mengembalikannya; `null` berarti tidak tersedia, bukan berarti tidak punya izin.

**Error:**

| HTTP | Kode | Kondisi | Fixture |
| --- | --- | --- | --- |
| 401 | `GITHUB_UNAUTHORIZED` | GitHub menolak token | `connection-unauthorized.json` |
| 403 | `GITHUB_FORBIDDEN` | Akses ditolak, bukan rate limit | `connection-forbidden.json` |
| 404 | `GITHUB_REPOSITORY_NOT_FOUND` | Repository tidak ditemukan/tidak dapat diakses | `connection-not-found.json` |
| 429 | `GITHUB_RATE_LIMITED` | Rate limit; `error.retry_after_seconds` bila ada | `connection-rate-limited.json` |
| 503 | `GITHUB_NOT_CONFIGURED` | Token/konfigurasi belum lengkap | `connection-not-configured.json` |
| 503 | `GITHUB_UNAVAILABLE` | Jaringan, timeout, 5xx | `connection-unavailable.json` |
| 503 | `GITHUB_INVALID_RESPONSE` | JSON/kontrak repository tidak valid | `connection-unavailable.json` (contoh struktur) |

Contoh unauthorized:

```json
{
  "success": false,
  "message": "GitHub authentication failed.",
  "error": { "code": "GITHUB_UNAUTHORIZED", "message": "GitHub authentication failed." },
  "data": { "status": "unauthorized" }
}
```

**Keterkaitan:** prasyarat koneksi untuk UC05; belum mengubah status issue apa pun. Frontend wajib memanggil endpoint ini sebelum formulir PR (FR-UC05-03). Jika gagal, tampilkan “Koneksi GitHub tidak tersedia, pengajuan belum diproses” dan jangan mengubah status issue (FR-UC05-04).

---

## 3. `GET /developer/github-mock/issues`

**Tujuan:** daftar issue/backlog yang dapat dilihat Developer.

**Authentication/authorization:** sesi aktif; role `Developer`.

**Query:**

| Nama | Wajib | Validasi | Keterangan |
| --- | --- | --- | --- |
| `status` | tidak | salah satu status SRS | Filter status. |
| `page` | tidak | integer ≥ 1 | Halaman. |
| `per_page` | tidak | integer 1–100 | Default 25. |

**Contoh request:**

```http
GET /api/v1/developer/github-mock/issues?status=Ready%20for%20Development&page=1&per_page=25
```

**Sukses HTTP 200:** `data` berisi array issue; `meta` berisi paginasi dan `is_mock=true`. Contoh lengkap: [`issues-list.json`](fixtures/uc04-uc05/issues-list.json).

Untuk empty state gunakan `?status=Ready%20for%20Merge` (tidak ada fixture dengan status itu): `data: []`, `meta.total: 0`, HTTP 200. Fixture: [`issues-empty.json`](fixtures/uc04-uc05/issues-empty.json).

**Error:** 401 tanpa sesi; 403 role salah; 422 status/paginasi tidak valid.

**Implementasi:** mock. **Keterkaitan:** FR-UC04-01, FR-UC04-02; dasar FR-UC05-01.

---

## 4. `GET /developer/github-mock/issues/{issueId}`

**Tujuan:** detail Maintenance Issue, repository, assignment, branch sumber, dan status push.

**Authentication/authorization:** sesi aktif; role `Developer`.

**Path:** `issueId` integer positif; contoh `102`.

**Sukses HTTP 200:** lihat [`issue-detail-102.json`](fixtures/uc04-uc05/issue-detail-102.json). Field inti:

```json
{
  "success": true,
  "data": {
    "issue_id": 102,
    "backlog_id": 502,
    "title": "Sesi login berakhir terlalu cepat",
    "status": "Ready for Pull Request",
    "assigned_to": { "user_id": 2, "nama": "Andi Developer", "github_login": null },
    "source_branch": "fix/login-session",
    "push_status": "detected",
    "last_push": {
      "branch": "fix/login-session",
      "commit": "mock-commit-login-001",
      "pushed_at": "2026-11-01T09:15:00+00:00"
    },
    "repository": {
      "owner": "AgilePemdigi-2026",
      "name": "MaintainGov",
      "full_name": "AgilePemdigi-2026/MaintainGov"
    },
    "is_mock": true
  },
  "meta": { "is_mock": true }
}
```

**Error:** 401, 403, 404 `ISSUE_NOT_FOUND`.

**Implementasi:** mock. **Keterkaitan:** FR-UC04-02, FR-UC04-07, FR-UC05-02.

---

## 5. `POST /developer/github-mock/issues/{issueId}/claim`

**Tujuan:** mensimulasikan klaim issue oleh Developer untuk membangun UI UC04.

**Authentication/authorization:** sesi aktif; role `Developer`; CSRF wajib.

**Request body:** tidak ada (boleh kosong).

**Validasi/logika mock:**

- Issue tidak ditemukan → 404.
- `assigned_to` sudah terisi → 409 `ISSUE_ALREADY_ASSIGNED` dengan pesan SRS “Issue telah diambil Developer lain”.
- Status bukan `Ready for Development` → 409 `ISSUE_NOT_CLAIMABLE`.
- Issue 101 (unassigned, Ready for Development) → 200 sukses mock.

**Sukses HTTP 200:** [`claim-success.json`](fixtures/uc04-uc05/claim-success.json). Ringkas:

```json
{
  "success": true,
  "message": "Mock response. Issue tidak benar-benar diubah di database atau GitHub.",
  "data": {
    "issue_id": 101,
    "status": "Sedang Dikerjakan",
    "assigned_to": { "user_id": 1, "nama": "Developer Mock", "github_login": null },
    "assigned_at": "2026-11-01T08:30:00+00:00",
    "is_mock": true
  },
  "meta": { "is_mock": true }
}
```

**Error:** 401, 403, 404, 409 ([`claim-conflict.json`](fixtures/uc04-uc05/claim-conflict.json)).

**Implementasi:** mock; **tidak ada perubahan database**. **Keterkaitan:** FR-UC04-03..08, BR-13..15.

---

## 6. `GET /developer/github-mock/issues/{issueId}/push-status`

**Tujuan:** membaca status event push untuk issue. Ini bukan endpoint untuk melakukan push.

**Authentication/authorization:** sesi aktif; role `Developer`.

**Path:** `issueId` integer.

**Sukses HTTP 200:** [`push-status.json`](fixtures/uc04-uc05/push-status.json). Ringkas:

```json
{
  "success": true,
  "data": {
    "issue_id": 101,
    "status": "not_detected",
    "branch": null,
    "last_push": null,
    "is_mock": true,
    "note": "Push dilakukan di luar MaintainGov. API ini hanya membaca status mock dan tidak melakukan operasi tulis."
  },
  "meta": { "is_mock": true }
}
```

Status yang mungkin: `detected`, `not_detected`. Issue 102 memiliki `detected` dan `last_push` fiktif.

**Error:** 401, 403, 404.

**Implementasi:** mock read-only. **Keterkaitan:** FR-UC04-09/10. **Asumsi:** webhook GitHub dan verifikasi signature belum diimplementasikan; push nyata tetap di luar sistem.

---

## 7. `GET /developer/github-mock/issues/{issueId}/branches`

**Tujuan:** menyediakan pilihan branch sumber/tujuan untuk formulir PR.

**Authentication/authorization:** sesi aktif; role `Developer`.

**Path:** `issueId` integer.

**Validasi/logika mock:** hanya issue berstatus `Ready for Pull Request` (fixture 102) yang mengembalikan daftar; status lain → 409 `ISSUE_NOT_READY_FOR_PULL_REQUEST`.

**Sukses HTTP 200:** [`branches.json`](fixtures/uc04-uc05/branches.json). Ringkas:

```json
{
  "success": true,
  "data": {
    "issue_id": 102,
    "source_branch": "fix/login-session",
    "suggested_target_branch": "main",
    "branches": [
      { "name": "main", "is_default": true, "sha": "mock-main-sha", "protected": true },
      { "name": "develop", "is_default": false, "sha": "mock-develop-sha", "protected": false },
      { "name": "fix/login-session", "is_default": false, "sha": "mock-login-session-sha", "protected": false }
    ],
    "is_mock": true
  },
  "meta": { "is_mock": true }
}
```

**Error:** 401, 403, 404, 409.

**Implementasi:** mock. **Keterkaitan:** FR-UC05-02/06.

---

## 8. `GET /developer/github-mock/issues/{issueId}/pull-requests`

**Tujuan:** melihat status PR terkait issue, termasuk empty state.

**Authentication/authorization:** sesi aktif; role `Developer`.

**Path:** `issueId` integer.

**Sukses HTTP 200:**

- Issue 101/103: `data: []`, `meta.total: 0` → [`pull-requests-empty.json`](fixtures/uc04-uc05/pull-requests-empty.json).
- Issue 102: satu PR mock `Open` → [`pull-requests-open.json`](fixtures/uc04-uc05/pull-requests-open.json).

```json
{
  "success": true,
  "data": [{
    "pull_request_id": 7001,
    "issue_id": 102,
    "status": "Open",
    "mock_state": "simulated_open",
    "lifecycle_state": "open",
    "review_state": "pending",
    "source_branch": "fix/login-session",
    "target_branch": "main",
    "github_pr_number": null,
    "github_pr_url": null,
    "is_mock": true
  }],
  "meta": { "is_mock": true, "issue_id": 102, "total": 1 }
}
```

**Error:** 401, 403, 404.

**Implementasi:** mock. **Keterkaitan:** FR-UC05-12; dasar UC06. **Catatan:** `github_pr_number`/`github_pr_url` null karena tidak ada PR nyata.

---

## 9. `POST /developer/github-mock/issues/{issueId}/pull-requests`

**Tujuan:** menyediakan stub pembuatan PR agar UI UC05 dapat dibangun tanpa izin tulis GitHub.

**Authentication/authorization:** sesi aktif; role `Developer`; CSRF wajib.

**Path:** `issueId` integer.

**Request body:**

| Field | Wajib | Validasi | Keterangan |
| --- | --- | --- | --- |
| `source_branch` | ya | string max 255, harus sama dengan branch issue | Branch sumber. |
| `target_branch` | ya | string max 255, beda dari source, harus ada di daftar mock | Branch tujuan. |
| `title` | ya | string max 255 | Judul PR. |
| `description` | ya | string max 5000 | Deskripsi perubahan. |

Contoh request:

```json
{
  "source_branch": "fix/login-session",
  "target_branch": "main",
  "title": "Perbaikan sesi login",
  "description": "Menyelaraskan masa berlaku sesi dengan konfigurasi aplikasi."
}
```

**Validasi/logika mock:**

- Issue tidak ditemukan → 404.
- Status bukan `Ready for Pull Request` → 409 `ISSUE_NOT_READY_FOR_PULL_REQUEST`.
- Source tidak cocok/target tidak valid → 422.
- Valid → HTTP 201, PR mock `Open`, **tanpa** memanggil GitHub/menyimpan DB.

**Sukses HTTP 201:** [`pull-request-create.mock.json`](fixtures/uc04-uc05/pull-request-create.mock.json).

```json
{
  "success": true,
  "message": "Mock response. Pull Request tidak dibuat di GitHub dan tidak disimpan ke database.",
  "data": {
    "pull_request_id": 7001,
    "status": "Open",
    "mock_state": "simulated_open",
    "github_pr_number": null,
    "github_pr_url": null,
    "is_mock": true
  },
  "meta": { "is_mock": true }
}
```

**Error:** 401, 403, 404, 409, 422.

**Implementasi:** stub; **tidak ada PR nyata**. Stub ini tidak memeriksa koneksi GitHub; frontend wajib memanggil `GET /github/connection` lebih dulu untuk FR-UC05-03/04. **Keterkaitan:** FR-UC05-05..14, BR-16..18. **Asumsi yang perlu konfirmasi:** path final mengikuti arsitektur `/maintenance-backlogs/{id}/pull-requests`, bukan namespace mock ini; metadata PR nyata baru diisi setelah integrasi tulis disetujui.

---

## 10. Contoh state UI

| State UI | Sumber | Contoh |
| --- | --- | --- |
| Loading | frontend | Tampilkan spinner saat request; tidak ada endpoint khusus. |
| Success connected | `GET /github/connection` | `connection-success.json` |
| Success mock UC04 | `POST .../claim` (issue 101) | `claim-success.json` |
| Success mock UC05 | `POST .../pull-requests` (issue 102) | `pull-request-create.mock.json` |
| Empty PR | `GET .../pull-requests` (issue 101) | `pull-requests-empty.json` |
| Empty issue | `GET .../issues?status=Ready%20for%20Merge` | `issues-empty.json` |
| Unauthorized | tanpa sesi | HTTP 401 `{"message":"Unauthenticated."}` |
| Forbidden | role Maintenance ke route Developer | HTTP 403 role message |
| Rate limited | koneksi GitHub | 429 `GITHUB_RATE_LIMITED` + `retry_after_seconds` |
| Unavailable | koneksi GitHub | 503 `GITHUB_UNAVAILABLE`/`GITHUB_NOT_CONFIGURED` |

## 11. Asumsi dan hal yang belum terverifikasi

1. Pemetaan path final UC04/UC05 belum diputuskan bersama Valisha/Demas. Namespace `/developer/github-mock` bersifat sementara dan dipisahkan dari route assignment/backlog.
2. GitHub App belum terverifikasi; token server-side saat ini belum boleh disebut sebagai GitHub App.
3. Operasi push dan webhook belum diimplementasikan; tidak ada endpoint yang melakukan push.
4. Pembuatan PR nyata, review, merge, dan sinkronisasi status belum diimplementasikan.
5. Skema `maintenance_backlog`, `github_repository`, dan `pull_request` belum ada; lihat [handoff database](../docs/GITHUB_DATABASE_HANDOFF.md).
6. `github_pr_number` dan `github_pr_url` pada mock sengaja null. Jangan membuat URL PR palsu.
