# Rencana Kerja MaintainGov - Kelompok 5

Versi 0.2 | Diperbarui 17 Oktober 2026 | Sprint 1, Week 10.

## 1. Target Sprint 1

Sprint 1 dimulai dari fitur yang dibutuhkan untuk mengolah feedback: login, hak akses, logout, dan UC01. Setiap fitur dipegang satu orang dari artefak sampai demo. Untuk pengerjaannya, anggota lain bisa membantu bagian frontend, backend, database, atau analisis.

Target akhir Week 10 adalah alur **login -> upload CSV -> analisis -> Maintenance Case Pending tersimpan dan tampil**. Week 11 dipakai untuk melengkapi pengujian, memperbaiki masalah, dan menyelesaikan Sprint 1. Total Sprint 1 tetap **10 poin** sesuai planning.

## 2. Dokumen acuan

Kebutuhan fitur mengikuti SRS, urutan pengerjaan mengikuti sprint planning, dan jadwal pengumpulan mengikuti panduan monitoring. Pilihan teknis yang belum ditentukan dibahas pada catatan keputusan di bagian 8.

Sumber acuan, memakai **nomor halaman PDF fisik** agar mudah dicari:

| ID | Sumber | Bagian yang dipakai |
| --- | --- | --- |
| S1 | [SRS MaintainGov](references/SRS_MaintainGov.pdf) | PDF hlm. 7: lingkup; 10-14: arsitektur/aktor; 31-34: antarmuka; 40-58: FR; 59-64: NFR/aturan bisnis; 69: CDM/PDM; 71-91: skenario/diagram; 92-94: TBD; 95-96: kontribusi sebelumnya |
| S2 | [Sprint Planning Kelompok 5](references/Sprint_Planning_Kelompok5.pdf) | PDF hlm. 2-6: prioritas, effort, sprint; 7-9: pembagian peran dan kondisi awal Week 9 |
| S3 | [Panduan Monitoring PPL Prak](references/Panduan_Monitoring_PPL_Prak.pdf) | PDF hlm. 1: Week 10-15, kepemilikan fitur, tracker; hlm. 2: evaluasi, working software, persetujuan PO, artefak PlantUML, dan BDD |
| S4 | Dataset Tangerang Live; [hasil pemeriksaan](DATASET_READINESS.md) | 7.149 ulasan, lexicon sentimen dan slang; pemetaan input dan kualitas resource analisis |

Jika ada perbedaan antar dokumen, catat dulu bagian yang perlu disesuaikan. PDF sumber tetap disimpan sebagai referensi.

## 3. Hal yang perlu diperhatikan

1. Week 9 pada planning dihitung sebagai persiapan. Jadwal sprint mengikuti panduan: Week 10-11, 12-13, dan 14-15.
2. Setiap anggota perlu memegang minimal satu fitur lengkap, meskipun pengerjaannya dibantu anggota lain.
3. Sprint 2 memuat 17 dari 34 poin. Koneksi GitHub perlu dicoba sejak Week 10 supaya masalah akses tidak baru ditemukan saat UC04/UC05 dikerjakan.
4. Analisis dapat dimulai dari aturan sederhana yang menghasilkan output nyata. Pendekatan ini tersedia sebagai pilihan awal di SRS; kualitas hasilnya tetap perlu diuji.
5. Koneksi repository diberi prioritas Should Have pada planning, tetapi diperlukan oleh UC04-UC06. Ketergantungan ini perlu dibahas saat meninjau backlog.
6. Simpan bukti commit, pengujian, dan demo tiap anggota. Panduan tidak mencantumkan jumlah minimum commit; cara penghitungannya perlu ditanyakan ke pengampu.

## 4. Tim dan tanggung jawab

Pembagian awal berikut melengkapi peran teknis pada sprint planning. Setiap anggota boleh membantu fitur lain. Jika penanggung jawab berpindah, perbarui issue dan tracker agar tanggung jawabnya tetap jelas.

| Anggota | Fokus keahlian dari planning | Tanggung jawab fitur | Dukungan utama | Reviewer utama |
| --- | --- | --- | --- | --- |
| Gerard Viriyandra / 187241047 | PM dan Lead Feedback Analysis | UC01 Menganalisis Feedback | Prioritas backlog, dataset, metode analisis, persiapan demo | Hanif untuk integrasi; Demas untuk persistensi |
| Demas Axel Marfiansyah / 187241043 | Backend dan Database | Login/Hak Akses, Logout, UC02 Validasi Case, UC06 Review PR | Migration, transaksi, sesi dan RBAC | Hanif; Valisha untuk alur pengguna |
| Muhammad Hanif / 187241062 | Backend Integration dan GitHub | UC04 Pengerjaan Issue, UC05 Pengajuan PR, koneksi repository | Kontrak API, adapter FastAPI, webhook, dukungan UC06 | Demas |
| Valisha Vine Ravelina / 187241012 | Frontend dan UI/UX | UC03 Edit Backlog; fitur Status/Riwayat | Shell aplikasi, komponen UI dan integrasi halaman lintas fitur | Demas untuk aturan data; Gerard untuk penerimaan |

Pemilik fitur mengikuti pengerjaan UI, API, database, artefak, dan pengujiannya. Contohnya, Valisha mengerjakan UC03 dengan bantuan Demas untuk validasi dan penyimpanan data, lalu menjelaskan seluruh alurnya saat demo.

Kepemilikan setelah UTS berbeda dari daftar kontribusi desain S1 hlm. 95-96. Lakukan serah terima diagram dan spesifikasi dari penulis lama ke pemilik fitur baru; pertahankan atribusi penulis lama. Setiap pemilik mencatat versi artefak yang ia periksa/perbarui.

Supaya pekerjaan tidak menumpuk, Hanif dan Demas saling membantu pada backend serta integrasi GitHub. Pemilik fitur juga membantu kebutuhan UI masing-masing. Gerard merekap progres dari issue dan catatan harian. Review PR dilakukan anggota lain.

Nama Product Owner/pihak penerima perlu dikonfirmasi. Gerard mengatur jadwal review; hasil penerimaan dicatat setelah review dilakukan.

## 5. Jadwal dan perkiraan effort

Poin mengikuti planning: S=1, M=2, L=3, XL=4, XXL=5. Poin menunjukkan perkiraan beban antarfitur; perkiraan jam tugas dicatat terpisah.

| Sprint | Minggu | Fitur | Poin | Kondisi selesai |
| --- | --- | --- | --- | --- |
| 1 | 10-11 | Login/RBAC 4; Logout 1; UC01 5 | 10 | Pengguna sesuai role dapat menganalisis CSV nyata hingga case Pending tersimpan dan terlihat; sesi/logout, kegagalan, artefak dan uji tervalidasi |
| 2 | 12-13 | UC02 3; UC03 2; UC04 4; UC05 5; koneksi GitHub 3 | 17 | Case valid menjadi backlog sekali; backlog diedit sesuai aturan; developer klaim issue, push terdeteksi, PR nyata terbentuk dan terhubung |
| 3 | 14-15 | UC06 5; Status/Riwayat 2; finalisasi lintas fitur | 7 | Review nyata, perubahan status dan riwayat dari feedback sampai penyelesaian; integrasi, BDD, UAT, perbaikan, dan paket UAS lengkap |
| Total | 6 minggu | 10 item backlog awal | **34** | Sesuai DoD dan persetujuan PO |

Pengujian dan perbaikan tetap membutuhkan waktu walaupun tidak mendapat poin tambahan di planning. Usulan awalnya, sisihkan sekitar 20% waktu sprint untuk pekerjaan tersebut, lalu sesuaikan dengan waktu tersedia tiap anggota.

Jika ada pekerjaan yang belum selesai, catat item dan sisa poinnya. Perubahan target atau estimasi ditulis beserta alasan dan tanggalnya. Burndown aktual diisi dari hasil pengerjaan, bukan disalin dari garis rencana.

## 6. Alur kerja kelompok

1. Simpan web, API, layanan analisis, kontrak, dan artefak di [repository kelompok](https://github.com/AgilePemdigi-2026/MaintainGov). Periksa akses tulis tiap anggota saat mulai bekerja.
2. Gunakan status issue `Backlog -> Ready -> In Progress -> In Review -> Done`. Jika terhambat, beri tanda `Blocked` dan tulis penyebabnya. Tiap issue dipegang satu orang.
3. Kerjakan satu tugas implementasi utama dahulu. Anggota lain bisa mulai memakai contoh data sesuai kontrak API sambil menunggu layanan siap.
4. Hanif menjaga kesesuaian kontrak API. Perubahan field dibahas dengan anggota yang membuat dan memakai API, lalu contoh responsnya ikut diperbarui.
5. Pembagian fitur dicatat di sini, rincian teknis di [Arsitektur](ARCHITECTURE.md), tugas di issue, dan hasil pengerjaan di [Catatan Progres](PROGRESS_TRACKER.md).
6. Pemilik fitur menyiapkan demo dan hasil uji. Anggota lain memeriksa, kemudian PO memberi keputusan saat review.

Pada hari kerja kelompok, masing-masing memberi kabar singkat: yang sudah dikerjakan, rencana berikutnya, dan kendala. Usulan jadwalnya adalah stand-up 10 menit, review PR paling lambat satu hari kerja, dan integrasi bersama dua kali seminggu. Demo awal dilakukan akhir Week 10; Sprint Review dan retrospektif pada akhir Week 11. Tanggal pastinya menyesuaikan jadwal kuliah.

Kalau satu tugas terhambat lebih dari satu sesi kerja, kabari Gerard supaya bisa dicari bantuan atau diubah urutan pengerjaannya. Perubahan yang memengaruhi target sprint dibahas sebelum deadline.

## 7. Kapan tugas mulai dan kapan dianggap selesai

Tugas bisa mulai setelah tujuan, penanggung jawab, reviewer, acuan FR/BR, dan hasil yang diharapkan sudah jelas. Jika ada API atau data yang belum tersedia, siapkan contoh sesuai kontrak yang sama terlebih dahulu.

**Done fitur:**

- Alur utama dan alternatif pada SRS bekerja melalui UI dan backend, termasuk persistensi serta batas role.
- Pengujian relevan lulus; aturan bisnis kritis memiliki pengujian otomatis; skenario BDD ditautkan ke hasil run nyata.
- Artefak fitur diperbarui: use case/specification, user story/scenario, GUI, activity, sequence dengan stereotypes robustness, class, dan database yang terpengaruh. Artefak bersama ditautkan, bukan disalin setiap fitur.
- Sumber teks PlantUML yang diwajibkan panduan tersimpan di repository; gambar/PDF saja tidak cukup. Cara menyertakan GUI asli dan representasi PlantUML dikonfirmasi ke pengampu.
- PR ditinjau orang lain, digabungkan, dan dapat dijalankan anggota lain dari petunjuk setup.
- Bukti berupa commit SHA, PR, hasil uji, dan demo masuk tracker; penerimaan PO dicatat pada review sesuai jadwal.

Mock dan screenshot dapat dipakai selama pengembangan. Fitur baru dihitung Done setelah aplikasi, pengujian, dan artefak yang diwajibkan sudah lengkap. Rekap akhirnya tetap dimasukkan ke template pengumpulan resmi.

## 8. Catatan keputusan

Tabel berikut mencatat pilihan implementasi, konflik pada dokumen, dan keputusan yang masih terbuka. Perubahan terhadap kebutuhan SRS perlu disinkronkan dengan artefak terkait. Kerjakan dahulu tugas yang tidak bergantung pada keputusan yang belum selesai.

| ID | Persoalan dan bukti | Rekomendasi | Pemutus / batas keputusan | Status |
| --- | --- | --- | --- | --- |
| D01 | S2 mencantumkan Week 9, S3 menjadwalkan Sprint 1 pada Week 10-11 | Week 9 menjadi persiapan; sprint resmi mengikuti S3 | Gerard / kickoff Week 10 | Mengikuti jadwal S3 |
| D02 | Peran teknis S2 belum menjamin fitur utuh per orang | Terapkan matriks bagian 4 dengan bantuan lintas anggota dan serah terima artefak | Gerard / koordinasi kelompok | Pembagian awal tersedia |
| D03 | S1 §3.4.4 meminta session cookie; §5.3 menyebut JWT | Gunakan session cookie Laravel, CSRF, dan RBAC backend; selaraskan bagian SRS yang masih menyebut JWT | Pilihan implementasi 16 Oktober 2026; sinkronisasi dokumen oleh Demas | Sesi cookie dipilih; sinkronisasi SRS belum selesai |
| D04 | S1 FR-AUTH-09 mengarahkan semua role ke analisis; BR-02 membatasi analisis ke Maintenance | Maintenance ke `/analisis-feedback`, Developer ke `/maintenance-backlog`; ubah skenario login secara tercatat | PO/kelompok / sebelum redirect diuji | Perlu konfirmasi |
| D05 | Dataset sudah diterima: 7.149 ulasan; label analisis, model, formula prioritas belum ditetapkan | Gunakan mapping dataset aktual; tetapkan aturan konflik kamus, label, metode, dan evaluasi | Gerard + Demas + PO / Hari 1-2 | Data diperiksa; aturan analisis perlu ditetapkan |
| D06 | S1 memuat GUI Register dan TBD registrasi, tetapi §1.4 dan BR-01 menyebut akun internal | Seed akun internal untuk versi 1; register publik tidak masuk Sprint 1 | PO/kelompok / kickoff | Usulan sesuai lingkup utama |
| D07 | S1 §5.3 menyebut admin dan semua data sensitif di-hash; aktor utama hanya dua dan token GitHub perlu dipakai ulang | Dua role; password di-hash; kredensial yang perlu dipakai ulang dienkripsi/disimpan sebagai secret; role admin hanya bila disetujui | Demas + PO / sebelum skema auth | Perlu konfirmasi |
| D08 | PDM belum cukup untuk assignment, hasil per-ulasan dan status akhir tertentu | Periksa perubahan skema pada dokumen Arsitektur; revisi PDM/class/status sebelum migration terkait | Demas + pemilik fitur / Sprint 1 untuk data analisis, sebelum UC04 untuk assignment | Perlu review |
| D09 | GitHub App ditetapkan SRS; akun pengaju dan reviewer harus dapat dibedakan | Uji identitas dan izin; uji dua akun GitHub terpisah dan user access token bila operasi atas nama pengguna diperlukan | Hanif + Demas / uji awal Week 10, selesai sebelum UC05/06 | Belum diverifikasi |
| D10 | Cara hitung commit dosen dan minimum belum ada di S3 | Konfirmasi metric: commit per author di repo/default branch, grafik profil, PR, periode dan minimum | Gerard ke pengampu / Week 10 | Perlu konfirmasi |
| D11 | Akses tulis tiap anggota, kapasitas, tanggal review, dan identitas PO belum lengkap | Periksa akses repository; isi jam tersedia, jadwal review, dan penerima fitur | Kelompok / kickoff | Akses baca lokal tersedia; rincian lainnya perlu dilengkapi |
| D12 | S1 skenario UC04 hlm. 74 hanya menampilkan reload sebelum Ready for PR, sedangkan FR mensyaratkan event push | BDD memasukkan event push terverifikasi sebelum reload; reload tidak mengubah workflow | Hanif + PO / sebelum BDD UC04 | Perlu sinkronisasi |

Format penutupan keputusan: `ID | tanggal | keputusan | alasan | dampak FR/artefak | penyetuju | PR perubahan`. Jangan menghapus konflik yang belum diselesaikan dari catatan.

## 9. Langkah awal

1. Periksa akses repo; gunakan pembagian D02 dan pilihan sesi cookie D03; tutup redirect D04, metode analisis D05, serta batas lingkup akun.
2. Konfirmasi kapasitas dan tanggal monitoring; buat issue dari [Week 10](WEEK10_EXECUTION.md).
3. Tetapkan kontrak awal dan skema CSV; jalankan integrasi sederhana paling lambat Hari 3.
4. Pada akhir Week 10, demo alur terintegrasi, catat pekerjaan yang perlu diselesaikan pada Week 11.
5. Terapkan [alur GitHub](GITHUB_WORKFLOW.md) sejak kontribusi pertama; simpan bukti melalui [tracker](PROGRESS_TRACKER.md).

