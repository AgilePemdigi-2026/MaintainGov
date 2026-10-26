# Baseline analisis UC01

Versi yang berjalan tetap rules-0.1-draft. Status persetujuan domain masih draft_pending_team_review.
Model sentimen asli Colab dipertahankan. Handoff model diverifikasi dengan SHA-256 3aa3bf587c44eec0e3991b549a020ebe050935528b7a527fa02a3e00f24d0872.
Perubahan penutupan Sprint 1 tidak melatih ulang model atau mengubah gold label yang belum tersedia.

| Rule | Kategori | Severity | Modul / fitur | Review yang diperlukan |
| --- | --- | --- | --- | --- |
| login_failure | defect | high | autentikasi / login | Reproduksi dengan akun uji dan versi aplikasi |
| application_crash | defect | high | aplikasi / stabilitas | Konteks langkah dan log crash |
| slow_application | performance | medium | aplikasi / respons aplikasi | Ukur waktu respons yang dikeluhkan |
| small_text | usability | low | antarmuka / keterbacaan | Ukuran layar dan ukuran teks |
| feature_request | request | low | belum diketahui / perlu klarifikasi | Pisahkan kebutuhan yang berbeda |

Aturan mencocokkan frasa setelah normalisasi; negasi dalam tiga token sebelumnya dengan boundary kalimat/klausa dipertimbangkan.
Jika beberapa rule cocok, urutan severity lalu key menentukan rule utama dan flag review diaktifkan.
Teks tanpa rule tetap mendapat hasil sentimen/kategori other, severity null, dan kebutuhan review; tidak dipaksa menjadi case.
Feature request memerlukan review dan tidak digabung otomatis satu sama lain.

Grouping per rule menggunakan TF-IDF dan agglomerative cosine clustering untuk subset sampai 600 record, threshold 0,45.
Di atas 600 record, fallback menggunakan teks normalisasi identik. Ini batas metode yang perlu direview; bukan klaim seluruh parafrasa tergabung.
Satu ulasan hanya menjadi anggota maksimal satu kandidat case pada baseline ini.

Prioritas penelitian dihitung sebagai 100 × (0,7 × severity/3 + 0,3 × min(frekuensi/10,1)), dengan low=1, medium=2, high=3.
Adapter membagi skor penelitian dengan 10 dan membulatkan untuk kontrak aplikasi 0–10. Contoh high, frekuensi satu menghasilkan 7,30/10.
Rekomendasi merupakan saran berbasis rule dan selalu memerlukan validasi. Seluruh case yang terbentuk tetap Pending.

## Evaluasi manusia

Artefak privat berisi annotation_template.csv dengan 100 sampel dan kolom label kosong.
Dua rater mengisi penilaian secara independen, reviewer menyelesaikan perbedaan dan mencatat consensus sentiment.
Isi annotator_a dan annotator_b dengan identitas dua rater yang berbeda, reviewer dengan identitas pemberi persetujuan,
label_source=human dan approved=true hanya setelah review benar-benar dilakukan.
Catat label awal rater pada kolom tambahan sentiment_a/sentiment_b bila hendak menghitung agreement.

Jangan memakai model prediction sebagai jawaban awal pada lembar blind annotation.
Simpan label, prediksi per ulasan dan teks sumber pada lokasi privat; evidence publik hanya agregat.
Jika sampel dipakai mengubah rule/model, jangan gunakan sampel itu untuk mengklaim evaluasi independen.

    python scripts/evaluate_human_annotations.py --annotations data/raw/annotation_template.csv --predictions data/raw/holdout_predictions_and_errors.csv --split-manifest data/raw/split_manifest.csv --output docs/evidence/sprint1-closure/human-evaluation.json

Evaluator hanya memakai label approved dari manusia. Dengan manifest, hanya partition test dievaluasi dan overlap text_group antarpartisi ditolak.
Tanpa manifest, laporan dilabeli audit sample dengan independensi belum diverifikasi.
Minimum mutu dan aturan penerimaan harus ditentukan reviewer/PO sebelum hasil evaluasi dipakai sebagai keputusan.
Jumlah label manusia approved pada bahan yang ditemukan saat pengerjaan ini: 0. Tidak ada angka akurasi manusia yang diisi secara otomatis.

URL sumber Kaggle, pembuat dan lisensi masih perlu dilengkapi pemilik dataset. Jangan menebaknya dari nama berkas.
