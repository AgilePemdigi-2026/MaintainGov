# Tugas MaintainGov - Week 10

Week 10 dipakai untuk menyiapkan aplikasi dan menjalankan alur analisis pertama. Rencana kerja dan catatan dataset sudah tersedia. Tanggal Hari 1-5 di bawah mengikuti hari kerja yang disepakati kelompok.

## 1. Target minggu ini

Targetnya, akun Maintenance bisa login, mengunggah sampel CSV Tangerang Live, dan melihat hasil analisis. Pemrosesan berjalan lewat Laravel dan FastAPI, lalu case berstatus Pending tersimpan di MySQL. Setelah logout, sesi lama tidak bisa dipakai lagi.

Week 11 dipakai untuk melengkapi pengujian, mengukur waktu proses, memperbaiki masalah keamanan, dan melakukan review Sprint 1. Bagian yang belum berjalan dicatat sebagai pekerjaan lanjutan.

## 2. Pembagian tugas

Setiap task memiliki satu penanggung jawab dan satu reviewer. Anggota lain dapat membantu atau mengambil alih tugas setelah issue dan tracker diperbarui. Pembagian pemilik fitur mengacu pada [Master Plan](MASTERPLAN.md).

Perkiraan waktu seluruh tugas adalah **43 jam untuk kelompok**, di luar rapat, bantuan antaranggota, dan review. Sesuaikan lagi setelah waktu tersedia tiap orang diketahui dan setup pertama dicoba. Tugas yang terlalu besar bisa dibagi menjadi beberapa hasil yang mudah diperiksa.

| ID | Penanggung jawab; pendukung | Yang dikerjakan dan diperiksa | Menunggu | Perkiraan | Reviewer |
| --- | --- | --- | --- | ---: | --- |
| W10-01 | Gerard; semua anggota | Menentukan hari kerja, pembagian fitur, dan jam tersedia. Memeriksa akses repo empat anggota dan membuat daftar issue | Tidak ada | 1 jam | Demas |
| W10-02 | Hanif; setiap pemilik aplikasi | Menyiapkan versi dan cara menjalankan web, API, analisis, serta database. Menyediakan env.example tanpa data rahasia | W10-01; akses repo | 3 jam | Demas |
| W10-03 | Gerard; Demas | Menentukan pemetaan CSV, label analisis, aturan konflik kamus, dan rumus prioritas awal. Memilih 50-100 sampel serta membahas ulasan tanpa issue | Dataset dan catatan awal tersedia | 3 jam | Demas + Valisha |
| W10-04 | Demas; Gerard | Membuat migration pengguna, batch, ulasan, dan case. Memeriksa relasi serta field yang boleh kosong; mencoba migration dan akun dua role pada DB bersih | W10-02/03 | 2 jam | Hanif |
| W10-05 | Demas; Hanif | Membuat API login/me/logout dan hak akses. Menguji credential salah, akses role lain (403), dan pemakaian sesi setelah logout | W10-02/04; pilihan sesi cookie | 3 jam | Hanif |
| W10-06 | Valisha; Demas | Membuat halaman login/logout dan navigasi sesuai role. Menghubungkannya ke API, termasuk pesan gagal dan halaman tujuan sesuai D04 | Contoh respons auth; integrasi W10-05 | 2 jam | Demas |
| W10-07 | Gerard; Hanif | Membuat analisis awal dari teks asli: kategori, sentimen, severity, issue, kelompok, prioritas, dan rekomendasi. Setiap case menyimpan ID ulasan asal | W10-03; aplikasi dasar W10-02 | 5 jam | Hanif + Demas |
| W10-08 | Hanif; Gerard + Demas | Menulis kontrak API, penghubung FastAPI, dan antrean Laravel. Mencoba status berhasil/gagal dan pengulangan proses; menyediakan contoh respons untuk UI | Draf W10-03; W10-02; hasil W10-07 | 4 jam | Demas |
| W10-09 | Demas; Hanif | Membuat validasi dan upload CSV. Menyimpan teks serta ID asal, mengembalikan batch (202), lalu menyimpan hasil sebagai case Pending dalam transaksi. Pengulangan tidak membuat case ganda | W10-03/04; W10-08 untuk integrasi | 4 jam | Hanif |
| W10-10 | Valisha; Gerard | Membuat form upload, petunjuk kolom, status proses, dan pesan error. Tombol Analisis kembali aktif setelah file pengganti valid dipilih | Contoh respons W10-08; API W10-09 | 2 jam | Gerard |
| W10-11 | Valisha; Gerard | Menampilkan hasil dan case Pending dari API beserta ulasan asal. Memeriksa hasil tetap tampil setelah refresh. Tombol validasi UC02 dikerjakan pada Sprint 2 | Contoh respons W10-08; hasil tersimpan W10-09 | 2 jam | Gerard |
| W10-12 | Hanif; seluruh pemilik komponen | Mencoba alur web -> API -> FastAPI -> DB. Menguji CSV salah, role salah, dan layanan mati; mencatat hasil, commit SHA, serta lingkungan uji | W10-05/06/07/08/09/10/11 | 3 jam | Demas |
| W10-13 | Hanif; Demas | Mencoba koneksi GitHub App pada repo uji, izin PR/review, dan webhook. Maksimal dua jam; catat hasil atau kendala akses | Akses eksternal; tidak menunggu analisis | 2 jam | Gerard |
| W10-14 | Gerard; penulis artefak sebelumnya | Memeriksa artefak UC01 dan file PlantUML: use case, story/scenario, activity, sequence, serta rekomendasi FR-UC01-18 | W10-03/07; serah terima SRS | 2 jam | Demas |
| W10-15 | Demas; Hanif | Menyesuaikan artefak login/logout dengan sesi cookie. Menerima artefak UC02, mencatat perubahan DB/class, dan menautkan hasil uji auth | W10-04/05; sumber artefak | 2 jam | Hanif |
| W10-16 | Valisha; Demas | Menerima artefak UC03 dan menyiapkan skenario edit berhasil/gagal. Memeriksa aturan edit sebelum issue dikerjakan serta menyiapkan contoh API Sprint 2 | SRS; serah terima artefak | 1 jam | Demas |
| W10-17 | Hanif; Gerard + Demas | Menerima artefak UC04/05. Memeriksa claim, push, dan PR; mencatat kebutuhan assigned_to, source_branch, serta event push sebelum status berubah | SRS; W10-13 bila tersedia | 1 jam | Gerard |
| W10-18 | Gerard; setiap anggota | Menyiapkan demo Week 10, mengisi progres aktual, dan mencatat hasil review serta pekerjaan Week 11. Tiap anggota menjelaskan bagiannya | W10-12 dan artefak terkait | 1 jam | Seluruh tim; PO jika tersedia |

Perkiraan per anggota: Gerard **12 jam**, Demas **11 jam**, Hanif **13 jam**, dan Valisha **7 jam**. Jika waktu yang tersedia lebih sedikit, dahulukan alur login sampai hasil analisis. Persiapan Sprint 2 dapat dipindah ke Week 11 dan dicatat pada tracker.

W10-16/17 adalah serah terima dan persiapan Sprint 2, sehingga tidak menambah poin fitur Sprint 1.

## 3. Urutan kerja dan dependensi

```text
Owner, akses, kapasitas (01)
  -> environment dan skeleton (02)
     + kontrak CSV/analisis (03)
       -> skema dan seed (04) -> auth API (05) -> integrasi UI auth (06)
       -> analisis awal (07) + kontrak/worker/adapter (08)
       -> upload dan persistensi (09)
       -> UI upload/hasil (10/11, mulai lebih awal dengan mock kontrak)
  -> integrasi + uji nyata (12)
  -> demo dan tracker (18)

Uji koneksi GitHub (13) dan serah terima artefak (14-17) bisa dikerjakan terpisah dari alur utama.
```

Dependensi implementasi tidak harus membuat anggota menunggu: Valisha membangun tampilan memakai fixture yang sesuai kontrak; Demas memakai fake AnalysisClient untuk menguji transaksi; Gerard menguji fungsi analisis dengan JSON; Hanif menguji adapter dengan respons contoh. Integrasi nyata wajib menggantikan mock sebelum demo dianggap bekerja.

## 4. Target per hari kerja kelompok

| Hari | Pemeriksaan hasil | Pemimpin sesi |
| --- | --- | --- |
| 1 | Sepakati owner/kapasitas/tanggal; akses repo; kontrak awal, resource dataset, skeleton dan seed mulai tersedia | Gerard |
| 2 | Login API dan UI dapat dicoba; parser dataset benar; analisis punya output awal yang mengikuti kontrak | Demas + Gerard |
| 3 | Integrasi minimum pertama: 10-20 ulasan nyata dapat sampai case Pending di database, meski UI hasil masih sederhana | Hanif |
| 4 | UI upload/hasil terhubung; uji kegagalan dan retry; peer review serta sinkronisasi artefak | Valisha + pemilik fitur |
| 5 | Demo sampel 50-100 ulasan; hasil uji dan commit masuk tracker; susun pekerjaan Week 11 dari gap nyata | Gerard + semua anggota |

Jika sampai Hari 3 alurnya belum terhubung, Gerard mengatur sesi kerja bersama untuk memeriksa kontrak API dan penghubung layanan. Penyempurnaan tampilan dan percobaan model tambahan dikerjakan setelah alur utama berjalan.

## 5. Skenario penerimaan dan bukti

| ID uji | Skenario | Ekspektasi | Acuan |
| --- | --- | --- | --- |
| AUTH-01 | Maintenance/Developer login dengan credential valid | Sesi dan role benar; route sesuai keputusan tercatat; akses role dibatasi backend | FR-AUTH-05..10; BR-02/03 |
| AUTH-02 | Credential salah atau field kosong | Tidak login; pesan sesuai SRS tanpa membocorkan credential mana yang salah | FR-AUTH-04/06 |
| AUTH-03 | Logout lalu pakai sesi lama | Redirect login, endpoint terproteksi menolak sesi lama | FR-AUTH-11..14 |
| UC01-01 | Maintenance mengunggah CSV sampel aktual yang valid | Batch Diproses -> Selesai; output nyata; case Pending dan asal ulasan tersimpan; hasil tetap ada setelah reload | FR-UC01-01..18 |
| UC01-02 | File salah/kolom wajib hilang | Error dan daftar kolom wajib; analisis belum dipanggil; file pengganti valid dapat diproses | FR-UC01-02..05 |
| UC01-03 | Teks CSV mempunyai koma, kutip atau newline | Record/field terbaca benar; teks tidak bergeser menjadi rating/tanggal | BR-04; dataset aktual |
| UC01-04 | FastAPI mati/timeout/output tidak valid | Batch Gagal; tidak ada notifikasi sukses atau case parsial; batch lain dapat diakses | NFR-SAF-03/10 |
| UC01-05 | Retry batch gagal atau job dikirim ulang | Batch yang sama dipulihkan; case dan ulasan hasil tidak berlipat | Integritas data; rancangan retry |
| RBAC-01 | Developer mengirim langsung request analisis | Backend menolak 403, walaupun request tidak melalui menu | FR-AUTH-10; BR-02 |

Simpan skenario di `tests/bdd/` dan hasil pengujian di `docs/evidence/`. Runner dipilih saat W10-02. Gunakan format Given/When/Then dari SRS dan hubungkan skenarionya ke aplikasi, lalu catat hasil run.

Contoh skenario yang harus dihubungkan ke aplikasi nyata:

```gherkin
Feature: Analyze user feedback
  Scenario: Valid feedback produces persisted pending cases
    Given I am authenticated as a Maintenance Team member
    And a reviewed CSV fixture contains actionable feedback
    When I upload the CSV and start analysis
    Then the analysis batch eventually has status "Selesai"
    And the generated Maintenance Cases have status "Pending"
    And each case is linked to its source reviews
    When I reload the result page
    Then the persisted results are still displayed
```

## 6. Persiapan monitoring Week 10

Gerard membawa ringkasan goal, perubahan scope dan risiko; Demas memperlihatkan auth/RBAC/migration; Hanif memperlihatkan request antarservice dan jalur gagal; Valisha memperlihatkan alur UI dan hubungan hasil ke API. Demo adalah satu aplikasi yang sama, bukan empat aplikasi terpisah.

Untuk setiap anggota: satu daftar issue/PR/commit miliknya, artefak yang diperiksa/diubah, hasil uji dan kendala beserta langkah berikutnya. Valisha tetap mencatat kepemilikan UC03 meskipun minggu ini membantu UI UC01; Hanif tetap memegang UC04/05 meskipun minggu ini mengerjakan fondasi integrasi.

## 7. Lanjutan Week 11 dan dua sprint berikutnya

- **Week 11:** tutup semua acceptance Sprint 1; evaluasi metode awal pada sampel terpisah; perbaiki aturan; uji 5.000/full dataset, batas upload, failure/retry; sinkronkan artefak; Sprint Review dan penerimaan PO. Pengujian yang belum lulus dicatat sebagai pekerjaan lanjutan.
- **Week 12:** UC02 + UC03 selesai lebih awal; transaksi dan izin edit diverifikasi; koneksi GitHub dan claim issue diintegrasikan.
- **Week 13:** UC04 + UC05 dan koneksi repository memenuhi DoD dengan GitHub nyata; reservasi waktu perbaikan karena 17 poin terkonsentrasi di sprint ini.
- **Week 14:** UC06 + status/riwayat; review dua akun, request changes/revisi dan sinkronisasi merged.
- **Week 15:** tutup gap, regression, BDD/UAT, bukti persetujuan PO, serta artefak dan dokumen UAS yang konsisten.

Pembagian poin tetap 10/17/7. Uji koneksi Week 10 membantu persiapan; fitur GitHub tetap dikerjakan pada sprint yang tercantum di planning.
