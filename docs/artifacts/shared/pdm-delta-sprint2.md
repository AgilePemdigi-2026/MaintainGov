# Delta PDM Sprint 2 — MaintainGov (W12-01)
Penanggung jawab: Demas Axel Marfiansyah | Reviewer: Hanif
Acuan: PDM SRS Gambar 6.3; Architecture Plan §5–§6 (BR-10, FR-UC04-07, FR-UC05-02); pdm-delta-sprint1.md.
Status: dicatat SEBELUM migration `maintenance_backlog` dibuat.

## Delta yang DIEKSEKUSI pada W12-01 (Sprint 2)
| Tabel | Perubahan terhadap PDM | Alasan / acuan |
| --- | --- | --- |
| maintenance_backlog | Tabel baru: `backlog_id`; `case_id` UNIQUE dengan FK restrict ke `maintenance_case`; `status` ENUM 4 nilai (`Ready for Development`, `Sedang Dikerjakan`, `Ready for Pull Request`, `Selesai`) default `Ready for Development`; `created_at`; `updated_at` (WAJIB); index `status` | BR-10: satu backlog utama per case valid; backlog langsung terbentuk saat case divalidasi Ya |
| maintenance_backlog (DIEKSEKUSI W12-01) | `assigned_to` unsignedBigInteger nullable + FK `ON DELETE SET NULL` ke `pengguna_sistem(user_id)`; `assigned_at` timestamp nullable; `source_branch` varchar(100) nullable | AC7: antisipasi relasi Maintenance Issue (FR-UC04-07, FR-UC05-02); `assigned_to` belum dikonfirmasi PDM sehingga nullable, FK SET NULL agar klaim tidak menghalangi penghapusan akun |

Aturan status backlog mengikuti SRS PDM & ERD final: `Ready for Development` → `Sedang Dikerjakan` → `Ready for Pull Request` → `Selesai` (BR-11). `Ready for Merge` adalah enum pada tabel `pull_request`, deferred sebelum UC06.

## Delta yang DICATAT tapi TIDAK dieksekusi sekarang (Sprint 2–3)
| Item | Rencana |
| --- | --- |
| ENUM `Ready for Merge` & pemisahan Closed/Merged pada `pull_request` | Sebelum UC06 |
| `github_repository`, `pull_request`, `code_review` | Sprint 2–3 |
| UNIQUE(case_id) sudah dieksekusi bersama pembuatan tabel | W12-01 |

## Keputusan terbuka yang memengaruhi skema ini
- Status backlog mengikuti SRS PDM & ERD final; nilai ke-4 adalah `Selesai`, bukan `Ready for Merge`. `docs/ARCHITECTURE.md` §6 (baris alur baseline backlog) ditandai **errata** untuk konfirmasi PO; `Ready for Merge` tetap valid sebagai status `pull_request`.
- Ketiga kolom assigned (`assigned_to`, `assigned_at`, `source_branch`) DIEKSEKUSI W12-01 sebagai nullable agar tidak memengaruhi insert backlog hasil validasi; FK `assigned_to` SET NULL ke `pengguna_sistem(user_id)`.
