Upload dan hasil analisis Sprint 1 memerlukan pagination seluruh data, pemulihan polling, serta perlindungan recovery dan payload job lama. Perubahan ini menambahkan API status/pagination, UI hasil dan sumber case, validasi upload/file replacement, pengaman login/submit dan kontrak runtime tanpa menghapus perbaikan auth/attempt dari PR #17/#18.

Refs #12, #13, #14, #15, #16.

Validasi lokal: 84 tes Laravel pada MySQL 8, 20 tes Python dengan model privat tepercaya, sembilan skenario BDD HTTP, lima tes Chromium desktop/mobile, serta typecheck/production build. Bukti browser dan pengukuran kapasitas/recovery tersedia di docs/evidence/sprint1-closure. Workflow lima job disiapkan; hasil CI untuk PR ini harus diperiksa setelah push. CI menggunakan model sintetis dan tidak membuktikan mutu model asli.

Lihat docs/SPRINT1_CLOSURE.md dan docs/SPRINT1_HANDOFF.md. Label manusia, persetujuan rule/domain, provenance dataset asli, peer review dan acceptance PO tetap terbuka. Issue tidak ditutup otomatis. Poin Done hanya berubah setelah penerimaan tercatat.
