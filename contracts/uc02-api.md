# Kontrak API UC02 — Peninjauan & Validasi Case (W12-01)

Acuan implementasi: `apps/api/app/Http/Controllers/MaintenanceCaseController.php`,
`apps/api/routes/api.php` (blok UC02), `docs/artifacts/shared/pdm-delta-sprint2.md`.
Pemakai kontrak: Valisha (Next.js frontend), Demas/Hanif (backend Laravel).

Semua endpoint di belakang middleware `['auth', 'role:Tim Maintenance'] dengan sesi cookie + CSRF. Prefix lengkap selalu `/api/v1`.

Format umum: seluruh respons ber-envelope `{success: bool, message: string, data: any}`; error 422 menambahkan key `errors` berisi detail per field.
Error 401/403 dari middleware mengikuti `RoleMiddleware`: `{success:false, message:"Akses ditolak. ...", }` tanpa `data`.

---

## 1. GET /api/v1/maintenance-cases

Daftar kandidat case. **Default hanya `status_validasi = Pending`** (AC1/FR-UC02-01); `?status=` opsional untuk melihat lintas status.

Parameter query:

| Param | Aturan | Default |
| --- | --- | --- |
| `status` | `sometimes\|in:Pending,Valid,Tidak Valid` | `Pending` |
| `page` | `sometimes\|integer\|min:1` | 1 |
| `per_page` | `sometimes\|integer\|min:1\|max:100` | 25 |

Respons 200:

```json
{
    "success": true,
    "message": "Daftar maintenance case.",
    "data": [
        {
            "case_id": 7,
            "user_id": null,
            "judul_issue": "Notifikasi tidak muncul",
            "kategori": "defect",
            "modul": "Notifikasi",
            "fitur": "Push Notifikasi",
            "severity": "High",
            "priority_score": 8.50,
            "ringkasan_masalah": "Notifikasi update layanan tidak diterima warga.",
            "recommendation": null,
            "status_validasi": "Pending",
            "validated_at": null,
            "created_at": "2026-11-01 12:41:19",
            "batch_id": 101,
            "group_key": "group-a-1"
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

## 2. GET /api/v1/maintenance-cases/{id}

Detail satu case + **ulasan sumber** (via `ulasan.case_id`, FR-UC02-03) termasuk hasil analisis per ulasan.

Respons 200:

```json
{
    "success": true,
    "message": "Detail maintenance case.",
    "data": {
        "case": {
            "case_id": 7,
            "user_id": null,
            "judul_issue": "Notifikasi tidak muncul",
            "kategori": "defect",
            "modul": "Notifikasi",
            "fitur": "Push Notifikasi",
            "severity": "High",
            "priority_score": 8.50,
            "ringkasan_masalah": "Notifikasi update layanan tidak diterima warga.",
            "recommendation": "Periksa konfigurasi push service.",
            "status_validasi": "Pending",
            "validated_at": null,
            "created_at": "2026-11-01 12:41:19",
            "batch_id": 101,
            "group_key": "group-a-1"
        },
        "reviews": [
            {
                "review_id": 501,
                "batch_id": 101,
                "case_id": 7,
                "source_review_id": "r-7-1",
                "rating": 2,
                "teks_ulasan": "Aplikasi gagal kirim notifikasi 1",
                "analysis_result": {"kategori": "defect", "sentimen": "negative", "severity": "high"},
                "tanggal_ulasan": "2026-11-01 10:00:00"
            }
        ]
    }
}
```

## 3. POST /api/v1/maintenance-cases/{id}/validate

Keputusan validasi (FR-UC02-04 s.d. 12, BR-08/09/10). Di dalam transaksi + `lockForUpdate`; hanya case `Pending` yang dapat divalidasi.

Body:

| Field | Aturan |
| --- | --- |
| `keputusan` | `required\|in:Ya,Tidak` |

Perilaku:
- `Ya` → case menjadi `Valid`, `user_id` = validator, `validated_at` terisi, dan **tepat satu** `maintenance_backlog` dibentuk (status awal `Ready for Development`, BR-10).
- `Tidak` → case menjadi `Tidak Valid` + validator + `validated_at`, **tanpa backlog** (BR-09).
- Case sudah bukan `Pending` → 409 (tidak ada perubahan).
- Konflik_UNIQUE backlog (sudah dibentuk proses lain) → 409 dengan **rollback penuh** (case tetap `Pending`).

Respons 200 (`Ya`):

```json
{
    "success": true,
    "message": "Case valid. Backlog dibentuk.",
    "data": {
        "case_id": 7,
        "status_validasi": "Valid",
        "validator_id": 3,
        "validated_at": "2026-11-01 13:20:45",
        "keputusan": "Ya",
        "backlog_id": 12
    }
}
```

Respons 200 (`Tidak`): `message: "Case ditandai tidak valid."`, `data.backlog_id = null`.

Contoh error:

```jsonc
// 401 (anonim)
{"success": false, "message": "Akses ditolak. Silakan login terlebih dahulu.", "data": null}

// 403 (Developer)
{"success": false, "message": "Akses ditolak. Role tidak memiliki izin.", "data": null}

// 404 (case hilang di antara proses)
{"success": false, "message": "Case tidak ditemukan.", "data": null}

// 409 - validasi ulang / bukan Pending
{"success": false, "message": "Hanya case Pending yang dapat divalidasi. Status saat ini: Valid.", "data": null}

// 409 - konflik unique backlog (rollback penuh)
{"success": false, "message": "Backlog untuk case ini sudah ada.", "data": null}

// 422 - keputusan di luar Ya/Tidak
{"success": false, "message": "Keputusan harus Ya atau Tidak.", "data": null, "errors": {"keputusan": ["Keputusan harus Ya atau Tidak."]}}
```

---

Catatan verifikasi (audit W12-01/02): tes di `apps/api/tests/Feature/MaintenanceCaseValidationTest.php` (12 tes) membungkus perilaku tabel di atas kecuali case 404 di dalam transaksi (terverifikasi lewat `test_detail_case_tidak_ditemukan` untuk path show).
