# API CONTRACT: GITHUB INTEGRATION UC04, UC05, UC06

**Base URL:** `/api/v1`  
**Authentication:** Session Cookie / Bearer Token via Laravel Sanctum  
**Header Wajib:**  
- `Accept: application/json`  
- `Content-Type: application/json`  
- `X-XSRF-TOKEN: <csrf-token>` (jika menggunakan browser session)  

---

## 1. UC04 — Klaim & Pengerjaan Maintenance Issue

### 1.1 Klaim Issue
- **Method / URL:** `POST /maintenance-backlogs/{issue}/claim`
- **Role:** `Developer`
- **Deskripsi:** Mengklaim issue `Ready for Development`, mengalokasikan ke pengembang yang sedang login, mengubah status ke `Sedang Dikerjakan`, membuat penamaan branch `maintenance/issue-{id}`, dan mencoba membuat branch di GitHub secara otomatis.
- **Request Body:** None
- **Response 200 (Success):**
  ```json
  {
    "success": true,
    "message": "Issue berhasil diklaim.",
    "data": {
      "issue_id": 7,
      "status": "Sedang Dikerjakan",
      "assigned_to": 3,
      "source_branch": "maintenance/issue-7",
      "branch_status": "created" // atau "failed" jika izin token GitHub kurang
    }
  }
  ```
- **Error Responses:**
  - `403 Forbidden` (`FORBIDDEN_ROLE`): Pengguna bukan bertindak sebagai Developer.
  - `409 Conflict` (`ISSUE_ALREADY_CLAIMED`): Issue telah diklaim oleh pengembang lain.

---

### 1.2 Retry / Siapkan Branch GitHub
- **Method / URL:** `POST /maintenance-backlogs/{issue}/branch`
- **Role:** `Developer` (Hanya pemilik klaim)
- **Deskripsi:** Mencoba ulang pembuatan branch di GitHub jika pembuatan otomatis saat klaim mengalami kegagalan izin/jaringan.
- **Response 200 (Success):**
  ```json
  {
    "success": true,
    "message": "Branch GitHub berhasil dibuat.",
    "data": {
      "branch": "maintenance/issue-7",
      "sha": "9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b4c3d2e1f0a"
    }
  }
  ```
- **Error Responses:**
  - `403 Forbidden` (`GITHUB_FORBIDDEN`): Token server tidak memiliki izin tulis branch (`Contents: Write`).

---

### 1.3 Verifikasi Push Manual
- **Method / URL:** `POST /maintenance-backlogs/{issue}/push-verifications`
- **Role:** `Developer` (Pemilik klaim)
- **Deskripsi:** Memverifikasi commit push pengembang secara langsung ke GitHub REST API. Jika valid, status issue otomatis naik menjadi `Ready for Pull Request`.
- **Request Body:**
  ```json
  {
    "commit_sha": "a1b2c3d4e5f60718293a4b5c6d7e8f9a0b1c2d3e"
  }
  ```
- **Response 200 (Success):**
  ```json
  {
    "success": true,
    "message": "Push berhasil diverifikasi. Issue siap diajukan Pull Request.",
    "data": {
      "issue_id": 7,
      "status": "Ready for Pull Request",
      "last_push_sha": "a1b2c3d4e5f60718293a4b5c6d7e8f9a0b1c2d3e",
      "source_branch": "maintenance/issue-7"
    }
  }
  ```
- **Error Responses:**
  - `404 Not Found` (`COMMIT_NOT_FOUND`): Commit SHA tidak ditemukan di branch GitHub.
  - `422 Unprocessable` (`VALIDATION_ERROR`): Format commit SHA tidak berupa 40 digit heksadesimal.

---

## 2. UC05 — Pembuatan Pull Request

### 2.1 Daftar Branch Target
- **Method / URL:** `GET /maintenance-backlogs/{issue}/branches`
- **Role:** `Developer` (Pemilik klaim)
- **Response 200 (Success):**
  ```json
  {
    "success": true,
    "data": {
      "issue_id": 7,
      "source_branch": "maintenance/issue-7",
      "suggested_target_branch": "main",
      "default_branch": "main",
      "branches": [
        {"name": "main", "sha": "1234...", "protected": true},
        {"name": "maintenance/issue-7", "sha": "abcd...", "protected": false}
      ]
    }
  }
  ```

---

### 2.2 Buat Pull Request
- **Method / URL:** `POST /maintenance-backlogs/{issue}/pull-requests`
- **Role:** `Developer` (Pemilik klaim)
- **Header Opsional:** `Idempotency-Key: <unique-string>`
- **Request Body:**
  ```json
  {
    "title": "Perbaikan bug validasi email",
    "description": "Menambahkan sanitasi input RFC 5322.",
    "target_branch": "main"
  }
  ```
- **Response 201 (Created) / 200 (Idempotent Replay):**
  ```json
  {
    "success": true,
    "message": "Pull Request berhasil dibuat.",
    "data": {
      "pull_request_id": 12,
      "github_pr_number": 42,
      "github_pr_url": "https://github.com/AgilePemdigi-2026/MaintainGov/pull/42",
      "title": "Perbaikan bug validasi email",
      "source_branch": "maintenance/issue-7",
      "target_branch": "main",
      "lifecycle_state": "open",
      "review_state": "pending"
    },
    "meta": {
      "idempotent_replay": false,
      "reconciled": false
    }
  }
  ```
- **Error Responses:**
  - `409 Conflict` (`ISSUE_NOT_READY_FOR_PULL_REQUEST`): Issue belum berstatus `Ready for Pull Request`.
  - `403 Forbidden` (`GITHUB_FORBIDDEN`): Token server tidak memiliki izin tulis Pull Request (`Pull requests: Write`).

---

## 3. UC06 — Review & Sinkronisasi Pull Request

### 3.1 Daftar Pull Request
- **Method / URL:** `GET /pull-requests`
- **Role:** `Tim Maintenance` (melihat semua), `Developer` (hanya melihat miliknya)
- **Query Parameters:**
  - `status`: `open` (default), `closed`, `merged`, `all`
  - `search`: string pencarian (judul, branch, nomor PR)
  - `page`: integer
  - `per_page`: integer (default 25, max 100)
- **Response 200 (Success):**
  ```json
  {
    "success": true,
    "message": "Daftar Pull Request.",
    "data": [
      {
        "pull_request_id": 12,
        "github_pr_number": 42,
        "github_pr_url": "https://github.com/AgilePemdigi-2026/MaintainGov/pull/42",
        "title": "Perbaikan bug validasi email",
        "source_branch": "maintenance/issue-7",
        "target_branch": "main",
        "lifecycle_state": "open",
        "review_state": "pending",
        "reviews_count": 0,
        "author": {
          "user_id": 3,
          "nama": "Raditya Dev",
          "github_login": "raditya-dev"
        },
        "issue": {
          "issue_id": 7,
          "backlog_id": 7,
          "title": "Bug validasi email",
          "status": "Ready for Pull Request",
          "source_branch": "maintenance/issue-7"
        }
      }
    ],
    "meta": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 25,
      "total": 1,
      "status": "open"
    }
  }
  ```

---

### 3.2 Detail Pull Request
- **Method / URL:** `GET /pull-requests/{id}`
- **Role:** `Tim Maintenance` atau `Developer` (pemilik PR)
- **Response 200 (Success):**
  Menampilkan metadata PR lengkap, ringkasan keputusan review (`approved`, `changes_requested`, `dismissed`), serta riwayat review terurut kronologis.

---

### 3.3 Sinkronisasi Status GitHub
- **Method / URL:** `POST /pull-requests/{id}/sync`
- **Role:** `Tim Maintenance` atau `Developer` (pemilik PR)
- **Deskripsi:** Mengambil snapshot otoritatif terbaru langsung dari GitHub API (`GET /pulls/{n}` dan `GET /pulls/{n}/reviews`), mengupdate cache lokal, dan menyelaraskan status `maintenance_backlog`.
- **Response 200 (Success):**
  ```json
  {
    "success": true,
    "message": "Pull Request berhasil disinkronkan dengan GitHub.",
    "data": {
      "pull_request_id": 12,
      "review_state": "approved",
      "lifecycle_state": "open",
      "issue": {
        "status": "Ready for Merge"
      }
    }
  }
  ```

---

### 3.4 Kirim Keputusan Review (Approve / Request Changes)
- **Method / URL:** `POST /pull-requests/{id}/reviews`
- **Role:** `Tim Maintenance` **SAJA**
- **Header Opsional:** `Idempotency-Key: <unique-string>`
- **Request Body:**
  ```json
  {
    "decision": "APPROVE", // atau "REQUEST_CHANGES"
    "notes": "Tambahkan unit test validasi." // Wajib diisi jika decision == REQUEST_CHANGES
  }
  ```
- **Response 201 (Created) / 200 (Idempotent Replay):**
  ```json
  {
    "success": true,
    "message": "Pull Request disetujui. Issue siap untuk merge.",
    "data": {
      "pull_request_id": 12,
      "review_state": "approved",
      "lifecycle_state": "open",
      "issue": {
        "status": "Ready for Merge"
      }
    },
    "meta": {
      "review": {
        "code_review_id": 901,
        "github_review_id": 5001,
        "github_state": "APPROVED",
        "reviewer_login": "budi-maintenance",
        "decision": "approved"
      },
      "created": true,
      "reconciled": false,
      "idempotent_replay": false,
      "sync_pending": false
    }
  }
  ```
- **Error Responses:**
  - `403 Forbidden` (`REVIEW_ROLE_FORBIDDEN`): Role bukan Tim Maintenance.
  - `403 Forbidden` (`REVIEW_SELF_FORBIDDEN`): Reviewer adalah author dari Pull Request.
  - `403 Forbidden` (`GITHUB_REVIEWER_IDENTITY_MISMATCH`): Login GitHub reviewer lokal tidak cocok dengan akun pemilik token server.
  - `409 Conflict` (`PULL_REQUEST_NOT_OPEN`): PR berstatus closed/merged sehingga tidak dapat direview.
  - `422 Unprocessable` (`REVIEW_NOTES_REQUIRED`): Keputusan `REQUEST_CHANGES` dikirim tanpa catatan perbaikan.

---

## 4. Webhook GitHub

- **Method / URL:** `POST /webhooks/github`
- **Authentication:** `X-Hub-Signature-256` HMAC SHA-256
- **Headers Wajib:**
  - `X-GitHub-Event`: `push`, `pull_request`, `pull_request_review`, atau `ping`
  - `X-GitHub-Delivery`: UUID unik delivery
  - `X-Hub-Signature-256`: `sha256=<hmac>`
- **Response 200 (Success):** Delivery diproses atau diakui sebagai duplikat idempoten.
- **Response 202 (Accepted):** Event diabaikan karena bukan branch/PR terdaftar.
- **Response 401 (Unauthorized):** Signature HMAC tidak valid.
