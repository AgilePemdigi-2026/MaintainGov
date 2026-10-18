# Data Fixtures

Folder ini berisi contoh data kecil dengan identitas disamarkan untuk pengujian.

| File | Isi |
| --- | --- |
| `sample_10_reviews.csv` | 10 ulasan fiktif yang mengikuti struktur kolom dataset Tangerang Live |

**Catatan:**
- Data mentah asli (7.149 ulasan) disimpan di `data/raw/` yang diabaikan oleh Git.
- Fixture ini dipakai untuk menguji alur upload → validasi → analisis tanpa bergantung pada dataset asli.
- Kolom CSV mengikuti pemetaan di [DATASET_READINESS.md](../../docs/DATASET_READINESS.md).
