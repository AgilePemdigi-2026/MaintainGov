# Catatan Dataset MaintainGov

Pemeriksaan dataset untuk kebutuhan implementasi UC01, 16 Oktober 2026. Berkas yang diperiksa: `archive (7).zip`. Sumber yang dicatat adalah Kaggle; tautan dataset, pembuat, versi, dan lisensinya masih perlu dilengkapi.

Data ini bisa dipakai untuk mencoba impor CSV dan metode analisis awal. Pengujian kualitas NLP masih membutuhkan sampel berlabel. Kamus slang dan sentimen perlu diperiksa karena beberapa istilah mempunyai lebih dari satu arti atau bobot.

## 1. Isi dan unit data

| File | Record data, tanpa header | Struktur | Penggunaan |
| --- | ---: | --- | --- |
| `Review Aplikasi Tangerang Live.csv` | **7.149** | reviewId, userName, Review Teks, rating, thumbsUpCount, date; pemisah koma | Satu record = satu ulasan; sumber input UC01 |
| `negative.tsv` | 6.608 | word, weight; pemisah tab | Kamus sentimen negatif |
| `positive.tsv` | 3.609 | word, weight; pemisah tab | Kamus sentimen positif |
| `slang.csv` | 16.158 | slang, formal; pemisah titik koma | Kandidat normalisasi bahasa informal |

ZIP berukuran 569.297 byte; CSV ulasan 1.004.486 byte. SHA-256 ZIP: `243ac29f1907baade790589ced4f331fc4e6d32e78691ee8477541aacdf1bdcf`.

Angka dihitung dengan parser CSV standar menggunakan delimiter eksplisit dan dukungan escaped/quoted fields. Deteksi dialek otomatis dapat salah membaca karakter kutip pada berkas ini. **Jumlah baris fisik atau pemisahan berdasarkan koma bukan penghitung record yang benar.** Jangan menandai data rusak hanya karena parser salah dikonfigurasi.

## 2. Temuan kualitas yang relevan

| Temuan | Bukti | Dampak dan tindak lanjut |
| --- | --- | --- |
| Struktur dan field inti lolos pemeriksaan awal | 7.149/7.149 record mempunyai 6 field; tidak ada field kosong; rating seluruhnya 1-5; thumbsUpCount bilangan bulat nonnegatif; date dapat dibaca format `%Y-%m-%d %H:%M:%S` | Ingestion dapat dimulai. Ini hanya validitas struktur/tipe, bukan jaminan seluruh makna teks benar |
| ID ulasan unik | Tidak ada reviewId ganda atau record identik penuh | Gunakan reviewId sumber untuk menelusuri asal data; tetap pakai primary key internal |
| Teks sama pada ulasan berbeda | Setelah casefold dan normalisasi spasi: 5.385 teks unik, 1.764 record tambahan dengan teks berulang, pada 159 kelompok teks | Jangan menghapus otomatis: ulasan ber-ID berbeda bisa merepresentasikan frekuensi nyata. Deduplikasi impor dan grouping issue adalah dua hal berbeda |
| Rentang waktu panjang | Tanggal minimum 18 Agustus 2016, maksimum 5 Agustus 2026; zona waktu sumber tidak diberikan | Data historis satu snapshot; jangan menganggap seluruh ulasan masalah aktif saat ini. Simpan tanggal sumber dan bahas filter periode untuk demo |
| Label kualitas NLP belum tersedia | Tidak ada gold label kategori, sentimen, severity, issue group atau priority | Buat sampel berlabel untuk evaluasi. Rating tidak otomatis menjadi severity, kategori, atau label sentimen yang benar |
| Konflik slang | 770 record tambahan dengan key slang normalisasi berulang; **742 key** mempunyai lebih dari satu nilai formal; 2 record identik penuh | Buat kamus terkurasi dan aturan konflik yang berversi; hindari pemilihan diam-diam berdasarkan urutan file |
| Konflik lexicon negatif | 3 key normalisasi mempunyai bobot berbeda | Putuskan aturan pemilihan/kurasi dan uji pada contoh |
| Key lexicon positif berulang | 2 record tambahan pada key normalisasi; tidak ada konflik bobot dalam file positif | Deduplicate key untuk mencegah double counting |
| Overlap polaritas lexicon | **1.142 istilah unik** muncul di file positif dan negatif | Tetapkan skor gabungan/konteks/precedence secara eksplisit. Jangan otomatis menghitung dua kali atau menganggap satu file selalu benar |

Rating pada record valid: 1 bintang=1.776; 2=423; 3=486; 4=502; 5=3.962. Gunakan strata rating untuk memilih sampel evaluasi yang beragam; bukan untuk menyimpulkan jumlah bug atau akurasi analisis.

## 3. Pemetaan data untuk UC01

Pemetaan awal mengikuti kolom pada dataset. Demas dan Gerard memeriksanya pada W10-03 sebelum dipakai oleh endpoint upload.

| Header sumber | Field aplikasi/analisis | Aturan awal |
| --- | --- | --- |
| reviewId | `source_review_id`; dipetakan ke `ulasan.review_id` internal | Wajib dan unik dalam satu impor; jangan mengganti primary key internal dengan teks UUID tanpa revisi PDM |
| userName | `nama_reviewer` atau alias tersamar | Tidak diperlukan NLP; contoh/evidence publik memakai alias; keputusan menyimpan mentah mengikuti kebutuhan kelompok |
| Review Teks | `teks_ulasan` / `text` | Wajib, tidak kosong sesudah trim; teks asli tetap dipertahankan |
| rating | `rating` | Integer 1-5; konteks pendukung |
| thumbsUpCount | Metadata sumber opsional | Tidak ada pada PDM inti; abaikan secara terdokumentasi atau tambah kolom melalui revisi |
| date | `tanggal_ulasan` | Parse format aktual; zona waktu sumber belum diketahui, jangan memberi label UTC tanpa dasar |

Untuk dataset ini, usulan kolom wajib upload: `reviewId`, `Review Teks`, `rating`, `date`. `userName` dan `thumbsUpCount` opsional untuk kontrak aplikasi; importer tetap menerima CSV asli enam kolom. Jika dataset lain akan didukung, tambah pemetaan header eksplisit, bukan mengubah arti kolom secara otomatis.

Kamus adalah resource internal analisis, bukan tiga batch feedback tambahan. Parser kamus harus menggunakan delimiter masing-masing. Berkas ZIP sumber tidak dieksekusi; hanya isinya yang dibaca sebagai data.

## 4. Tugas yang langsung dihasilkan

- Gerard: kurasi slang/lexicon, taxonomy, aturan kasus tanpa issue, formula prioritas dan sampel label; pilih metode awal berdasarkan hasil pemeriksaan ini.
- Demas: pemetaan header, source_review_id, tanggal dan nullability/constraint; importer mendukung CSV dengan kutip, koma dan newline dalam teks.
- Hanif: kontrak Laravel-FastAPI memakai ID internal konsisten; fixture untuk quoted text dan output gagal/duplikat.
- Valisha: tampilkan nama file, jumlah record valid, daftar kolom wajib, pesan error, status pemrosesan dan hasil; contoh menggunakan identitas tersamar.

Sampel Week 10: 50-100 record beragam dengan source_review_id tetap dapat ditelusuri. Uji Week 11 mencakup 5.000 dan seluruh 7.149 ulasan; full dataset berada di bawah batas jumlah baris/ukuran awal SRS. Bukti kapasitas tetap harus diukur.

## 5. Provenance dan pengulangan pemeriksaan

Hasil agregat tersedia pada [dataset_profile.json](evidence/dataset_profile.json); pemeriksaan dapat diulang dengan [skrip profil](../scripts/profile_dataset.py). Skrip membaca ZIP langsung, tidak mengubah CSV atau memilih label/kurasi secara otomatis. Pemeriksaan ini tidak mengklaim mendeteksi seluruh duplikasi semantik atau mengukur akurasi model.

Contoh menjalankan ulang dari root repo dengan Python yang tersedia:

```powershell
python scripts/profile_dataset.py "C:\Users\LENOVO\Downloads\archive (7).zip" docs/evidence/dataset_profile.json
```

Raw ZIP disimpan di laptop pengembang dan tidak masuk repository. Sebelum membagikan data mentah, lengkapi URL Kaggle, pembuat, versi/tanggal unduh, lisensi, dan aturan atribusi. Catat sumber tersebut di `data/README.md` saat resource analisis disiapkan. Fixture dan evidence dalam repo memakai data seperlunya dengan identitas tersamar.
