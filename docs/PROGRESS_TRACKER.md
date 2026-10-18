# Catatan Progres MaintainGov

Isi catatan ini setelah pengerjaan atau review, lalu gunakan untuk melengkapi template monitoring resmi. Rencana kerja dan profil dataset sudah tersedia. Status implementasi di bawah masih perlu diperiksa melalui kode, pengujian, dan demo. Acuan jadwal ada di [Rencana Kerja](MASTERPLAN.md).

## 1. Identitas periode

| Field | Isi |
| --- | --- |
| Kelompok / produk | 5 / MaintainGov |
| Sprint / minggu | Sprint 1 / Week 10-11; laporan awal Week 10 |
| Repository | https://github.com/AgilePemdigi-2026/MaintainGov |
| Tanggal mulai / review | Diisi setelah jadwal kelompok ditetapkan |
| Product Owner / pemberi penerimaan | Nama dan kewenangan perlu dikonfirmasi |
| Goal | Login/RBAC, logout, CSV dianalisis hingga Maintenance Case Pending tersimpan dan terlihat |
| Baseline Sprint 1 / proyek | 10 poin / 34 poin |
| SHA/versi yang didemokan | Belum ada demo aplikasi |

## 2. Waktu tersedia tiap anggota

| Anggota | Jam tersedia Week 10 | Jam tersedia Week 11 | Pekerjaan utama Week 10 (perkiraan) | Reviewer/dukungan dan kendala waktu |
| --- | --- | --- | ---: | --- |
| Gerard | Diisi anggota | Diisi anggota | 12 jam | Tambahkan waktu PM/review |
| Demas | Diisi anggota | Diisi anggota | 11 jam | Review DB dan dukungan UC03/integrasi |
| Hanif | Diisi anggota | Diisi anggota | 13 jam | Adapter, review auth dan uji koneksi eksternal |
| Valisha | Diisi anggota | Diisi anggota | 7 jam | Bantuan UI lintas fitur dan review alur |

Perkiraan waktu belum mencakup rapat, review, dan bantuan antaranggota. Isi jam tersedia masing-masing sebelum menetapkan target. Beban individu juga dilihat dari bantuan pada fitur lain, bukan hanya poin fitur yang dipegang.

## 3. Status fitur dan poin awal

| Fitur | Pemilik | Sprint | Poin | Status aktual | Issue / PR / SHA / bukti |
| --- | --- | ---: | ---: | --- | --- |
| Login dan Hak Akses | Demas | 1 | 4 | Belum diverifikasi | Belum diisi |
| Logout | Demas | 1 | 1 | Belum diverifikasi | Belum diisi |
| UC01 Menganalisis Feedback | Gerard | 1 | 5 | Belum diverifikasi | Profil dataset tersedia; implementasi belum diverifikasi |
| UC02 Validasi Case | Demas | 2 | 3 | Belum diverifikasi | Belum diisi |
| UC03 Edit Backlog | Valisha | 2 | 2 | Belum diverifikasi | Belum diisi |
| UC04 Pengerjaan Issue | Hanif | 2 | 4 | Belum diverifikasi | Belum diisi |
| UC05 Pengajuan PR | Hanif | 2 | 5 | Belum diverifikasi | Belum diisi |
| Koneksi Repository | Hanif | 2 | 3 | Belum diverifikasi | Uji awal Week 10 tidak otomatis menutup seluruh fitur |
| UC06 Review PR | Demas | 3 | 5 | Belum diverifikasi | Belum diisi |
| Status dan Riwayat | Valisha | 3 | 2 | Belum diverifikasi | Belum diisi |

Fitur Done hanya setelah kriteria selesai terpenuhi. Task dan subtugas tidak menambah total 34 poin secara terpisah. Jika fitur dipecah menjadi story baru, catat persetujuan, total estimasi baru dan jembatan terhadap baseline lama.

## 4. Log harian dan kontribusi

Duplikasi satu baris per anggota per hari kerja, tanpa mengisi hasil yang belum terjadi.

| Tanggal | Anggota | Task / issue | Hasil nyata sejak update terakhir | Berikutnya | Blocker / tindakan / penanggung jawab | Commit / PR / bukti |
| --- | --- | --- | --- | --- | --- | --- |
| Diisi | Gerard | W10-... / #... | Diisi | Diisi | Diisi atau tidak ada | URL/SHA |
| Diisi | Demas | W10-... / #... | Diisi | Diisi | Diisi atau tidak ada | URL/SHA |
| 2026-10-18 | Hanif | W10-02 | Setup web (Next.js), api (Laravel+MySQL), analysis (FastAPI), contracts, fixtures, dan test pass | Review setup bersama Demas, lanjut W10-08 | Tidak ada | docs/evidence/w10-02-setup.md |
| Diisi | Valisha | W10-... / #... | Diisi | Diisi | Diisi atau tidak ada | URL/SHA |

Rekap individu saat review: fitur yang dimiliki; artefak yang ditulis/diperbarui dan sumber sebelumnya; implementasi; pengujian; review terhadap anggota lain; jumlah commit beserta periodenya; kendala dan tindak lanjut. Jumlah commit tidak menggantikan bukti fungsi yang berjalan.

## 5. Burndown

Rencana poin dari sprint planning:

| Titik | Poin rencana selesai pada periode | Sisa ideal proyek | Sisa aktual terverifikasi |
| --- | ---: | ---: | --- |
| Awal Sprint 1 | 0 | 34 | Belum diisi |
| Akhir Sprint 1 | 10 | 24 | Belum diisi |
| Akhir Sprint 2 | 17 | 7 | Belum diisi |
| Akhir Sprint 3 | 7 | 0 | Belum diisi |

Untuk Sprint 1, buat satu baris per hari kerja yang disepakati:

| Hari/tanggal | Sisa ideal Sprint 1 | Fitur yang baru memenuhi Done | Poin Done kumulatif | Sisa aktual Sprint 1 | Scope/estimasi berubah dan alasan |
| --- | --- | --- | --- | --- | --- |
| Awal sprint | 10 | Diisi berdasarkan bukti | Diisi | Diisi | Baseline 10 |
| Setiap hari kerja | 10 x (1 - hari_berjalan / total_hari_kerja) | Diisi | Diisi | 10 - poin Done kumulatif | Diisi bila ada |
| Review akhir | 0 | Diisi | Diisi | Diisi | Carry-over bila ada |

Garis ideal menunjukkan rencana. Sisa aktual berkurang setelah fitur memenuhi kriteria Done. Untuk tugas yang masih berjalan, catat status atau sisa jam secara terpisah. Jika target berubah, simpan rencana awal dan catat alasan perubahannya.

## 6. Risiko awal dan penanganan

| ID | Risiko | Penanggung jawab | Indikator/peristiwa | Tindakan dan batas waktu | Status |
| --- | --- | --- | --- | --- | --- |
| R01 | Akses tulis tim atau GitHub App produk belum terverifikasi | Gerard + Hanif | Akses tulis tiap anggota dan instalasi App produk belum diuji | Periksa akses 4 anggota saat kickoff; coba koneksi App pada Week 10 dan catat kendalanya | Terbuka |
| R02 | Kontrak layanan berbeda sehingga integrasi terlambat | Hanif | UI/API/analysis memakai field/status berbeda | Kontrak v0.1 Hari 1-2; alur nyata Hari 3 | Terbuka |
| R03 | NLP menghasilkan label tidak konsisten | Gerard | Konflik lexicon/slang dan gold label belum ada | Kurasi aturan + sampel berlabel terpisah; metode awal dan evaluasi Week 10-11 | Terbuka |
| R04 | Beban Sprint 2 terlalu besar | Gerard | 17 poin, GitHub menghambat UC04/05 | Uji koneksi lebih awal, kurangi antrean UI/backend; review kapasitas Week 11 | Terbuka |
| R05 | PDM tidak mendukung aturan SRS | Demas | assigned_to/waktu proses/status belum lengkap | Catat perubahan, perbarui PDM/class dan migration sebelum fitur terkait | Terbuka |
| R06 | Artefak hanya gambar, sumber PlantUML belum tersedia | Pemilik fitur; Gerard merekap | PDF menyertakan diagram tetapi file sumber belum diperiksa | Minta sumber asli dari anggota; rekonstruksi dan review bila tidak ada | Terbuka |
| R07 | Kontribusi tidak teratribusi / terlambat terlihat | Setiap anggota | Email salah, belum push, belum merge, atau squash menggabungkan riwayat | Verifikasi identitas, push tiap hasil bermakna, review dan integrasi rutin | Terbuka |
| R08 | Dataset historis dianggap masalah aktif dan kamus dianggap label benar | Gerard | Ulasan 2016-2026; overlap lexicon tinggi | Simpan tanggal, aturan konflik dan batas interpretasi; cek sampel dengan manusia | Terbuka |
| R09 | Jadwal atau peran penerima belum jelas | Gerard | Kapasitas/tanggal review/nama PO kosong | Lengkapi sebelum komitmen sprint dan demonstrasi penerimaan | Terbuka |

## 7. Keterlacakan dan inventaris artefak

Hubungkan setiap fitur dengan `FR/BR -> use case/story -> artefak -> task/issue -> PR/SHA -> hasil uji -> penerimaan`. Saat menerima artefak dari anggota lain, nama penulis sebelumnya tetap dicatat.

| Cakupan | Pemilik | FR/BR utama | Artefak minimum yang diperiksa/ditautkan | Bukti uji |
| --- | --- | --- | --- | --- |
| Login/logout | Demas | FR-AUTH-01..14 | Spec/story/scenario, GUI, activity, sequence, class/DB auth | AUTH-01..03; RBAC-01 |
| UC01 | Gerard | FR-UC01-01..18 | Spec/story/scenario, GUI analisis, activity/sequence, class/DB data, kontrak dan metode | UC01-01..05; evaluasi NLP |
| UC02 | Demas | FR-UC02-01..14; BR-06..11 | Spec/story/scenario, GUI case, activity/sequence, transaksi dan DB | Valid/tidak valid; satu backlog per case |
| UC03 | Valisha | FR-UC03-01..10; BR-12 | Spec/story/scenario, GUI edit, activity/sequence, DB field edit | Simpan valid; field kosong; edit setelah claim ditolak |
| UC04 | Hanif | FR-UC04-01..11; BR-13..15 | Spec/story/scenario, GUI issue, activity/sequence, assignment/branch | Claim bersamaan; event push sebelum status berubah |
| UC05 | Hanif | FR-UC05-01..14; BR-16..18 | Spec/story/scenario, GUI PR, activity/sequence, metadata GitHub | PR nyata; gagal/timeout; retry aman |
| UC06 | Demas | FR-UC06-01..16; BR-19..22 | Spec/story/scenario, GUI review, activity/sequence, data reviewer/status | Dua akun; approve/request changes/revisi/merge eksternal |
| Lintas fitur | Semua; Gerard merekap | BR-23/24; NFR yang relevan | Use case diagram/spec, user stories, GUI, DB/CDM/PDM, class dan architecture; sumber PlantUML yang diperlukan | Integration/regression/BDD/UAT/performa/backup |

Usulan lokasi sumber: `docs/artifacts/<auth|uc01|uc02|uc03|uc04|uc05|uc06|shared>/`. Sumber asli PlantUML belum diberikan melalui unggahan; gambar di PDF bukan bukti file `.puml` sudah ada. Jika perlu rekonstruksi, tandai sebagai rekonstruksi, review terhadap diagram PDF, dan periksa stereotypes boundary/control/entity pada sequence sesuai panduan. Konfirmasikan format GUI dan representasi artefak yang diminta pengampu.

## 8. Bukti review dan penerimaan

| Tanggal | Sprint/fitur | Versi/SHA dan lingkungan | Skenario yang didemokan | Hasil / gap | Reviewer | PO dan keputusan | Tindak lanjut / tenggat |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Belum diisi | Belum diisi | Belum diisi | Belum diisi | Belum diuji | Belum diisi | Belum ada persetujuan | Belum diisi |

Lampirkan hasil run BDD/aplikasi, tangkapan demo yang relevan, dan bukti persetujuan PO sesuai aturan kelas. Persetujuan harus berasal dari pihak yang berwenang. Tautkan perubahan kebutuhan ke D01-D12; pilihan desain autentikasi berbeda dari penerimaan fitur aplikasi yang sudah berjalan.
