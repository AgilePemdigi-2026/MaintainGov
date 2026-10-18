# Bukti Penyelesaian W10-04 — Skema Database Sprint 1
Penanggung jawab: Demas | Reviewer: Hanif | Tanggal: YYYY-MM-DD
SHA branch: <isi setelah commit> | Lingkungan: MySQL <versi>, Laravel <versi>, PHP <versi>

## 1. Perubahan artefak
- Catatan delta: docs/artifacts/shared/pdm-delta-sprint1.md

## 2. Langkah menjalankan (dapat diulang)
php artisan migrate:fresh --env=testing --seed
php artisan test --env=testing --filter=SchemaSprint1Test

## 3. Output migrate:fresh + seed (tempel mentah)
<tempel output>

## 4. Hasil uji constraint
| Skenario | Harapan | Hasil |
| --- | --- | --- |
| Insert valid 4 tabel | sukses | PASS |
| Duplikat (batch, source_review_id) | ditolak | PASS |
| ulasan.case_id null | diterima | PASS |
| case Pending user_id null | diterima | PASS |
| FK ulasan->batch palsu | ditolak | PASS |
| rating = 6 | ditolak | PASS |

## 5. Keamanan
Tidak ada kredensial pribadi/konfigurasi DB asli di repo; password uji berasal dari env terdokumentasi.