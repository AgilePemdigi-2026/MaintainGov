# Notebook Colab MaintainGov

`MaintainGov_UC01_End_to_End_Colab.ipynb` memuat eksperimen UC01 untuk W10-03,
W10-07 dan persiapan handoff analisis. Kode analisis berdiri sendiri dalam
notebook; repository privat dan token GitHub tidak diperlukan.

## Menjalankan

1. Buka <https://colab.research.google.com/> → **File → Upload notebook**.
2. Unggah file notebook terbaru dan gunakan runtime Python 3.13 dengan CPU.
3. Pilih **Runtime → Run all**, lalu unggah satu ZIP dataset saat dialog muncul.
4. Tunggu seluruh tahap selesai. Grafik, tabel dan pemeriksaan tampil berurutan.
5. Pada akhir proses, unduh **MaintainGov_hasil_lengkap.zip** yang ditawarkan
   otomatis. Simpan juga salinan notebook dengan output melalui menu File.

Notebook menyiapkan lingkungan analisis terpisah dan tidak mengganti NumPy
yang telah dimuat oleh sesi Colab. Tidak ada langkah restart manual atau
pengaturan instalasi yang perlu diubah. Pemasangan awal memerlukan internet
dan waktu tambahan. Runtime Colab bersifat sementara; simpan hasil sebelum
runtime berakhir. Browser mungkin meminta izin penyimpanan unduhan.

## Input

ZIP memuat CSV Tangerang Live, `positive.tsv`, `negative.tsv`, dan `slang.csv`.
Satu folder induk diperbolehkan. Nama kolom dan format ada di bagian 3 notebook.
Dataset sumber lain membutuhkan pemetaan kolom; rating yang tidak tersedia
tidak boleh dibuat-buat. Notebook tidak melakukan scraping.

Untuk mode anotasi manusia, lengkapi dan review `annotations.csv`, masukkan ke
ZIP, kemudian pilih `LABEL_MODE="human"` pada konfigurasi sebelum Run all.
Pertahankan partisi development/test dan jangan menggunakan prediksi model
sebagai label reviewed. Default `rating_proxy` dapat berjalan dengan ZIP asli.

## Analisis dan interpretasi

Notebook mencakup audit/karantina, EDA, audit resource bahasa, normalisasi,
sentimen lexicon, template anotasi, fitur teks/numerik, word/character TF-IDF,
chi-square selection di dalam fold, grouped holdout/CV, baseline mayoritas,
Naive Bayes/logistic/SVM, tuning, ablation, learning curve, holdout, bootstrap
grup, precision–recall, analisis kesalahan, kandidat case dan prioritas draf.

Seluruh baris valid diproses. `SEARCH_ITER=12` adalah jumlah konfigurasi tuning,
bukan jumlah baris. Macro-F1, recall per kelas dan balanced accuracy perlu
dibaca bersama accuracy serta baseline mayoritas. Pada mode proksi, metrik
mengukur kesesuaian terhadap pemetaan rating, belum sentimen manusia.

## Paket hasil

ZIP lengkap memuat dua paket dan panduan penggunaan:

| Paket di dalam ZIP lengkap | Isi | Penggunaan |
|---|---|---|
| `MaintainGov_evidence_agregat.zip` | Metrik/provenance, audit, CV, tuning, model card, grafik dan hasil pemeriksaan | Bukti eksperimen; tinjau istilah grafik sebelum masuk GitHub |
| `MaintainGov_artefak_privat.zip` | Model, preprocessing, requirements, kamus, anotasi, prediksi per ulasan dan calon case | Handoff teknis privat; tetap di luar Git |

ZIP lengkap ikut membawa artefak privat, sehingga tidak diunggah ke GitHub.
Notebook menguji parser/ZIP, pemisahan grup, reload model, inference dalam
proses baru, input inference tidak valid, keterlacakan case dan integritas ZIP.
Contoh uji sintetis tidak menjadi label manusia atau bukti akurasi model.

Serahkan paket inference kepada Hanif untuk diuji lagi di FastAPI. Demas
mereview mapping ID dan transaksi case Pending. Payload notebook masih draf
riset, ID-nya ID demo, dan case belum tersimpan pada MySQL. Integrasi aplikasi,
BDD, target NFR dan penerimaan PO diperiksa terpisah.

## Mengulang smoke test lokal

Gunakan lingkungan terpisah berisi `nbformat`, `nbclient`, `nbconvert`,
`ipykernel` dan paket versi pada sel persiapan. Dari root proyek:

```powershell
python -m unittest discover -s notebooks/tests -v
python scripts/smoke_colab_notebook.py --zip "C:/path/archive (7).zip" --run-dir tmp/smoke-baru --colab-shim
```

Gunakan direktori run baru. Runner mengeksekusi setiap sel dengan interpreter
yang dipilih, memeriksa output dan menyimpan hasil di `tmp/`. `--colab-shim`
menguji cabang kode upload/download menggunakan file lokal, termasuk satu ZIP
unduhan lengkap. Dialog browser, jaringan dan akun Google tidak disimulasikan.
Tanpa flag ini, input memakai path lokal dan paket tetap disimpan di folder run.

`--worker-python` dapat memilih interpreter analisis untuk pengujian lokal;
tidak diperlukan di Colab. `--save-executed` digunakan hanya untuk menyimpan
run penuh pada dataset asli ke notebook yang diserahkan. Run fixture sintetis
atau tuning yang dikurangi tidak menggantikan notebook tersebut.

Bukti terakhir: [`uc01_colab_validation.json`](../docs/evidence/uc01_colab_validation.json).
