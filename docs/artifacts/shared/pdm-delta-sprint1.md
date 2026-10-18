# Delta PDM Sprint 1 — MaintainGov (W10-04)
Penanggung jawab: Demas Axel Marfiansyah | Reviewer: Hanif
Acuan: PDM SRS Gambar 6.3; Architecture Plan §5; DATASET_READINESS §3.
Status: dicatat SEBELUM migration dibuat.

## Delta yang DIEKSEKUSI pada W10-04 (Sprint 1)
| Tabel | Perubahan terhadap PDM | Alasan / acuan |
| --- | --- | --- |
| analysis_batch | + started_at, completed_at, error_message, attempt, analysis_version | NFR-PERF-10, NFR-SAF-03, retry idempoten |
| ulasan | + source_review_id dengan UNIQUE (batch_id, source_review_id); case_id nullable | reviewId sumber untuk traceability; ulasan tersimpan sebelum case terbentuk |
| maintenance_case | user_id = validator, nullable saat Pending; validated_at nullable | Mencegah pengunggah batch dianggap validator (FR-UC02-06) |
| ulasan | + CHECK rating BETWEEN 1 AND 5 | Validitas data dataset (rating 1–5) |
| pengguna_sistem | email UNIQUE; role ENUM dua nilai; tanpa register/reset password | BR-01, D06, D07 |
| (keputusan) | Migration bawaan users/password_reset_tokens tidak dipakai; jobs dipertahankan untuk queue | Architecture §3, BR-01 |

## Delta yang DICATAT tapi TIDAK dieksekusi sekarang (Sprint 2–3)
| Item | Rencana |
| --- | --- |
| maintenance_backlog.assigned_to / assigned_at / source_branch + UNIQUE(case_id) | Migration tersendiri sebelum UC04 |
| ENUM Ready for Merge & pemisahan Closed/Merged pada pull_request | Sebelum UC06 |
| Tabel hasil analisis per ulasan berversi | Keputusan bersama Gerard, rencana W10-09 |
| github_repository, pull_request, code_review | Sprint 2–3 |

## Keputusan terbuka yang memengaruhi skema ini
- D06: akun internal tanpa register publik (dijalankan sebagai asumsi Sprint 1).
- D07: dua role; password di-hash; admin hanya bila disetujui PO.
- D08: issue W10-04 adalah eksekusi review delta bagian Sprint 1.