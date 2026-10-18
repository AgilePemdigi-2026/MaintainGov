# MaintainGov UC01 — FastAPI NLP Service (Prototype v0.1)

Aplikasi ini mengubah artefak NLP dan aturan analisis `MaintainGov_UC01_run.ipynb` menjadi layanan inference independen. Kode mengikuti notebook untuk preprocessing, prediksi Logistic Regression, pemilihan issue domain, grouping TF-IDF/agglomerative, dan rumus skor prioritas.

> **Batas status**: prototype penelitian, bukan UC01 end-to-end. Tidak menyimpan ke MySQL, tidak menjalankan Laravel/Next.js, tidak menyediakan autentikasi. Gunakan hanya pada localhost atau private internal network; jangan mengekspos model atau API ke internet.

## 1. Persyaratan

- Python 3.13 (sejalan dengan runtime notebook 2026-10-18)
- Artefak `joblib` hasil training dari sumber yang dipercaya. Jangan mengganti file joblib dengan file tidak tepercaya.
- Instal environment bersih; gunakan versi `scikit-learn==1.6.1` yang cocok dengan artefak.

```bash
cd MaintainGov_FastAPI_UC01
python -m venv .venv
# Windows PowerShell: .venv\Scripts\Activate.ps1
# macOS/Linux: source .venv/bin/activate
python -m pip install -r requirements.txt
python -m pytest -q
python -m uvicorn app.main:app --host 127.0.0.1 --port 8001
```

## 2. Coba API

Buka <http://127.0.0.1:8001/docs>.

```bash
curl http://127.0.0.1:8001/health
curl -X POST http://127.0.0.1:8001/v1/analyze -H "Content-Type: application/json" --data-binary @examples/request.json
curl -X POST "http://127.0.0.1:8001/v1/analyze-csv?batch_id=batch-001" -F "file=@examples/reviews.csv"
```

JSON `/v1/analyze` adalah kontrak internal yang sebaiknya diintegrasikan Laravel. Laravel menangani user auth, validasi/penyimpanan `analysis_batch`/`ulasan`, dan insert `maintenance_case` + relasi ulasannya. `/v1/analyze-csv` hanyalah convenience endpoint untuk pengujian manual. Hindari memanggil service dari frontend secara langsung.

## 3. File penting

- `app/maintain_gov_preprocess.py` — file asli hasil ekspor Colab
- `artifacts/sentiment_pipeline.joblib` — model terlatih
- `artifacts/slang_map.json` — normalisasi slang
- `artifacts/domain_rules.json` — 5 aturan domain status *draft*
- `app/pipeline.py` — full candidate-case pipeline yang dipindahkan dari notebook
- `app/main.py` — endpoint FastAPI
- `tests/` — pengujian kontrak awal

## 4. Kesetaraan terhadap notebook

- Parsing CSV memakai kolom wajib `reviewId`, `Review Teks`, `rating`, `date` seperti notebook. Baris invalid dihitung sebagai `quarantined`, tanpa menyimpan record karantina.
- Grouping mengikuti threshold cosine distance 0.45 dan prioritas `100*(0.7*severity_value/3+0.3*min(frequency/10,1))`.
- Alur tidak menghitung sentimen lexicon karena kamus positif/negatif penuh **tidak ikut** dalam ZIP privat. `sentiment_lexicon` dikembalikan `null`, bukan dibuat-buat.
- `group_key` adalah pengenal dalam batch saja, bukan ID database permanen.
- Model rating proxy F1 0.6196 pada holdout; bukan kualitas label manual.
- Kasus `Pending` berarti kandidat belum divalidasi dan `persisted:false` sampai Laravel menyimpan.
- Model/versi library harus dipertahankan untuk menghindari incompatibility atau perubahan prediksi.

## 5. Next Sprint

1. Uji `pytest -q` pada environment yang telah diinstal sesuai requirements; validasi `/docs`.
2. Tambah endpoint Laravel `POST /api/analysis-batches` (CSV, upload), sinkronisasi data dan antrian job.
3. Implementasikan migration batch, ulasan, case, dan case_review sesuai PDM.
4. Buat Next.js Analisis Feedback, loading/progress/status, dan daftar case.
5. Pastikan case yang disetujui pada UC02 dapat menjadi backlog; lakukan acceptance test.

**Jangan commit folder `artifacts/` ke repository publik:** file model, slang map, dan sampel hasil eksperimen dapat memuat informasi terkait dataset sumber. Verifikasi lisensi dataset sebelum publikasi.

## 6. Status verifikasi pada lingkungan penyusun

- Pemeriksaan integritas kedua ZIP asli: **lolos**, dengan `testzip() = None`.
- Pengujian kontrak API, validasi input, domain rules, dan CSV dengan **stub sentiment model**: **6 tes lolos**.
- Tiga pengujian API yang memakai model sebenarnya **belum dijalankan** di lingkungan penyusun: `scikit-learn 1.8.0` tidak kompatibel dengan model `1.6.1`, dan akses penginstalan paket ke indeks Python tidak tersedia. Mereka otomatis *skip* sampai versi yang cocok digunakan.
- Ketika `pip install -r requirements.txt` selesai pada laptop pengguna, tiga tes itu akan dijalankan bersama tes lainnya. Total yang diharapkan: 9 tes; jangan mengklaim berhasil sampai output terminal menunjukkannya.
- `category`, `severity`, `issue`, grouping dan priority memakai aturan riset yang belum menerima persetujuan domain.

## 7. Menjalankan pada laptop ini

Lingkungan `.venv` sudah disiapkan dengan Python 3.13.16, NumPy 2.2.6 dan
scikit-learn 1.6.1. Perintah `python` bawaan laptop mengarah ke Python 3.14;
gunakan executable di `.venv` untuk proyek ini.

Klik dua kali `JALANKAN_API.cmd`, tunggu `Application startup complete`, lalu
buka <http://127.0.0.1:8001/docs>. Biarkan jendela server tetap terbuka.

Untuk mengulang pengujian dari folder proyek:

```powershell
.\.venv\Scripts\python.exe -m pytest -q
```

Verifikasi lokal: 9 tes lulus tanpa skip, `pip check` lulus, serta endpoint
health, dokumentasi, analisis JSON dan upload CSV merespons HTTP 200 dengan
model asli. Satu peringatan deprecation berasal dari TestClient.
Rincian tersimpan pada `LOCAL_VALIDATION.json`. Pengujian ini belum mencakup
integrasi Laravel atau penyimpanan MySQL.

## Penggunaan dari repository kelompok

Folder ini menyimpan source prototipe W10-07 secara terpisah dari scaffold
`services/analysis`; penghubungan layanan tetap mengikuti review kontrak W10-08.
Endpoint prototipe `/v1/analyze` perlu diselaraskan dengan kontrak kelompok.

Model tidak masuk Git. Buat folder `artifacts/` di sini dan salin
`sentiment_pipeline.joblib`, `slang_map.json` dan `domain_rules.json` dari paket
privat hasil Colab. Salin model card bila diperlukan. Python 3.13 dan
scikit-learn 1.6.1 diperlukan; `.venv` pada laptop penyusun tidak ikut repository.
Setelah environment dan requirements tersedia, jalankan `python -m pytest -q`.
Sembilan tes pada bukti lokal memakai model asli; clone tanpa artefak privat
belum siap menjalankan layanan atau tes inference asli.
