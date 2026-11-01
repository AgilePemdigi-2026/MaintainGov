# Kontrak API UC03 — Maintenance Backlog (W12-02, issue #22)

Acuan implementasi: `apps/api/app/Http/Controllers/MaintenanceBacklogController.php`,
`apps/api/routes/api.php` (blok UC03), `docs/artifacts/shared/pdm-delta-sprint2.md`
(§Hardening index + keputusan **D-W12-02-01**), `apps/api/config/cors.php` (PATCH/PUT/DELETE diizinkan).
Pemakai kontrak: **Valisha (Next.js frontend)** — halaman backlog; Demas/Hanif (backend).

Semua endpoint di belakang middleware `['auth', 'role:Tim Maintenance']` (sesi cookie + CSRF).
Prefix lengkap selalu `/api/v1`. CORS `PATCH` diizinkan untuk frontend browser.

Format umum: seluruh respons ber-envelope `{success: bool, message: string, data: any}`; 422 menambahkan key `errors` per field (pesan Bahasa Indonesia, `apps/api/lang/id/validation.php`).

---

## 1. GET /api/v1/maintenance-backlog

Daftar backlog tersambung ringkasan case-nya (satu query JOIN, tanpa N+1). Urutan deterministik:
**prioritas High > Medium > Low** (dinormalisasi `LOWER(severity)`), lalu `priority_score DESC`, lalu `backlog_id ASC`.

Parameter query:

| Param | Aturan | Default |
| --- | --- | --- |
| `status` | `sometimes\|in:Ready for Development,Sedang Dikerjakan,Ready for Pull Request,Selesai` | (semua status) |
| `page` | `sometimes\|integer\|min:1` | 1 |
| `per_page` | `sometimes\|integer\|min:1\|max:100` | 25 |

Respons 200:

```json
{
    "success": true,
    "message": "Daftar maintenance backlog.",
    "data": [
        {
            "backlog_id": 12,
            "case_id": 7,
            "status": "Ready for Development",
            "assigned_to": null,
            "assigned_at": null,
            "source_branch": null,
            "created_at": "2026-11-01 13:20:45",
            "updated_at": "2026-11-01 13:20:45",
            "judul_issue": "Notifikasi tidak muncul",
            "kategori": "defect",
            "modul": "Notifikasi",
            "fitur": "Push Notifikasi",
            "severity": "High",
            "priority_score": 9.00,
            "ringkasan_masalah": "Notifikasi update layanan tidak diterima warga.",
            "status_validasi": "Valid"
        }
    ],
    "meta": {"current_page": 1, "last_page": 1, "per_page": 25, "total": 1}
}
```

## 2. GET /api/v1/maintenance-backlog/{id}

Detail backlog + ringkasan case lengkap (termasuk `recommendation`) via `case_id`.

Respons 200:

```json
{
    "success": true,
    "message": "Detail maintenance backlog.",
    "data": {
        "backlog_id": 12,
        "case_id": 7,
        "status": "Ready for Development",
        "assigned_to": null,
        "assigned_at": null,
        "source_branch": null,
        "created_at": "2026-11-01 13:20:45",
        "updated_at": "2026-11-01 13:20:45",
        "judul_issue": "Notifikasi tidak muncul",
        "kategori": "defect",
        "modul": "Notifikasi",
        "fitur": "Push Notifikasi",
        "severity": "High",
        "priority_score": 9.00,
        "ringkasan_masalah": "Notifikasi update layanan tidak diterima warga.",
        "recommendation": "Periksa konfigurasi push service.",
        "status_validasi": "Valid",
        "user_id": 3,
        "validated_at": "2026-11-01 13:20:45",
        "created_at": "2026-11-01 13:20:45"
    }
}
```

## 3. PATCH /api/v1/maintenance-backlog/{id}

Edit backlog oleh Tim Maintenance **hanya saat status masih `Ready for Development`** (GUARD BR-12). Field yang ditulis langsung ke row `maintenance_case` terkait; `priority` adalah label yang dimap ke bucket `priority_score` (lihat "Perilaku prioritas" di bawah).

Body:

| Field | Aturan | Wajib |
| --- | --- | --- |
| `ringkasan_masalah` | `required\|string` | **Ya** |
| `priority` | `sometimes\|in:High,Medium,Low` | tidak |
| `severity` | `sometimes\|string\|max:20` | tidak |
| `modul` | `sometimes\|string\|max:100` | tidak |

Perilaku `updated_at`:
- Setiap PATCH yang lolos guard **selalu** memutakhirkan `maintenance_backlog.updated_at` (timestamps), sekalipun hanya `ringkasan_masalah` yang berubah — dipakai sebagai penafsir "backlog terakhir ditinjau".

Perilaku prioritas (**D-W12-02-01**):
- UI memilih label `High/Medium/Low`; backend menuliskan bucket `priority_score` tetap: **High = 9.00, Medium = 5.50, Low = 2.00**.
- Skor model ML (0–10) **tergantikan** oleh nilai bucket ini; ordering list tetap konsisten (`High > Medium > Low` → skor → `backlog_id`).
- Keputusan menetapkan **tanpa kolom baru**; perubahan ke skema tersendiri menunggu konfirmasi PO.

Respons 200:

```json
{
    "success": true,
    "message": "Backlog diperbarui.",
    "data": {
        "backlog_id": 12,
        "case_id": 7,
        "status": "Ready for Development",
        "case": {
            "case_id": 7,
            "judul_issue": "Notifikasi tidak muncul",
            "kategori": "defect",
            "fitur": "Push Notifikasi",
            "status_validasi": "Valid",
            "created_at": "2026-11-01 13:20:45",
            "ringkasan_masalah": "Ringkasan hasil edit Tim Maintenance.",
            "modul": "Modul Notifikasi",
            "severity": "Medium",
            "priority_score": 5.50
        },
        "updated_at": "2026-11-01 14:05:00"
    }
}
```

Contoh error:

```jsonc
// 401 (anonim)
{"success": false, "message": "Akses ditolak. Silakan login terlebih dahulu.", "data": null}

// 403 (Developer)
{"success": false, "message": "Akses ditolak. Role tidak memiliki izin.", "data": null}

// 404 (backlog tidak ada)
{"success": false, "message": "Backlog tidak ditemukan.", "data": null}

// 409 (BR-12: status bukan Ready for Development; tidak ada field yang berubah)
{"success": false, "message": "Perubahan hanya diizinkan saat backlog masih Ready for Development (BR-12). Status saat ini: Sedang Dikerjakan.", "data": null}

// 422 (field bermasalah; pesan Bahasa Indonesia)
{
    "success": false,
    "message": "Perubahan tidak valid.",
    "data": null,
    "errors": {
        "priority": ["Nilai prioritas harus salah satu dari: High, Medium, Low."]
    }
}
```
