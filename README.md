# MaintainGov

MaintainGov membantu tim maintenance mengolah feedback pengguna menjadi case, backlog, dan pekerjaan pengembangan yang terhubung dengan pull request di GitHub.

Untuk **Sprint 1, Week 10-11**, pengerjaan difokuskan pada login, hak akses, logout, dan analisis feedback dari CSV. Teknologi yang digunakan adalah Next.js/TypeScript, Laravel, FastAPI, dan MySQL.

Pembagian tugas **Kelompok 5** ada di [Rencana Kerja](docs/MASTERPLAN.md). Rincian tugas minggu ini ada di [Tugas Week 10](docs/WEEK10_EXECUTION.md).

| Dokumen | Kegunaan |
| --- | --- |
| [Petunjuk Setup](docs/SETUP_GUIDE.md) | Cara install, konfigurasi, dan menjalankan semua komponen |
| [Rencana Kerja](docs/MASTERPLAN.md) | Pembagian fitur, jadwal sprint, dan hal yang masih perlu dibahas |
| [Arsitektur](docs/ARCHITECTURE.md) | Pembagian komponen, API, database, dan alur pemrosesan |
| [Catatan Dataset](docs/DATASET_READINESS.md) | Struktur 7.149 ulasan Tangerang Live dan hasil pemeriksaan kamus |
| [Tugas Week 10](docs/WEEK10_EXECUTION.md) | Tugas tiap anggota, urutan pengerjaan, perkiraan waktu, dan target demo |
| [Alur GitHub](docs/GITHUB_WORKFLOW.md) | Cara membuat branch, commit, pull request, dan review |
| [Catatan Progres](docs/PROGRESS_TRACKER.md) | Progres fitur, kendala, burndown, dan hasil review |

SRS, sprint planning, dan panduan monitoring tersimpan di `docs/references/`.

Progres diisi setelah pengerjaan dan pengujian dilakukan. Target yang tertulis di rencana belum berarti fiturnya sudah selesai.

Template issue dan pull request tersedia di `.github/`. Setiap tugas memiliki satu penanggung jawab dan satu reviewer. Anggota lain tetap bisa membantu jika dibutuhkan.

## Hasil analisis UC01

- [Notebook Colab](notebooks/MaintainGov_UC01_End_to_End_Colab.ipynb): Run all, input ZIP, analisis dan ekspor.
- [Hasil run kelompok](notebooks/runs/MaintainGov_UC01_run.ipynb) dan [evidence agregat](docs/evidence/uc01-colab-run/).
- [Prototipe inference dengan model Colab](experiments/uc01-api/README.md) dan [hasil pengujian lokal](docs/evidence/uc01_fastapi_local_validation.json).

Prototipe berada di `experiments/uc01-api` untuk handoff W10-07/W10-08.
Layanan `services/analysis` dari setup kelompok tetap tersedia. Endpoint dan
kontrak perlu diselaraskan sebelum integrasi Laravel. Model serta hasil per
ulasan disimpan privat; notebook belum menutup penerimaan UC01 atau persistensi MySQL.
