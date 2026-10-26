# Penutupan teknis Sprint 1 — Week 11

Tanggal: 26 Oktober 2026. Baseline Git: 6383a700d3b0f5e0240dfa8f9323ef73631b555d.
Status: implementasi penutupan disiapkan di working tree, belum di-commit/push. Assignment dan commit dilakukan pengguna.
Lingkup tetap Login/RBAC (4), Logout (1), UC01 (5), total Sprint 1 10 poin dan proyek 34 poin.

## Perubahan yang disiapkan

- Kontrak internal diekspor dari Pydantic runtime, dengan pemeriksaan drift dan contoh payload valid.
- Nilai sentimen internal positive/negative/neutral; severity review tanpa issue boleh null, severity case low/medium/high; skor aplikasi 0–10.
- API status batch ringan serta halaman reviews/cases, maksimal 100 record per halaman. Case memiliki urutan prioritas lalu ID yang stabil.
- UI menyediakan pagination seluruh hasil, sumber case berpaginasi, field kategori/severity/issue/modul/fitur dan notifikasi berhasil.
- Polling memakai backoff terbatas dan tombol cek status; 401 meminta login, error sementara tidak mematikan proses backend.
- Setelah CSV ditolak dengan 422, tombol analisis menunggu pemilihan file baru. Validasi file server membatasi 10.000.000 byte dan 10.000 record.
- Job membawa attempt. Hasil/failure dari attempt lama tidak mengubah attempt baru. Payload job lama tanpa field baru tetap didukung saat claim.
- analysis:recover-stale menandai attempt yang sudah mulai dan kedaluwarsa sebagai Gagal, tanpa menyentuh antrean yang belum diklaim. Recovery tersedia pada scheduler dan CLI.
- Tersedia BDD HTTP executable, workflow CI, evaluator label manusia, benchmark yang dapat diulang serta sembilan sumber diagram PlantUML/SVG.
- Laravel Boost dipasang mengikuti AGENTS.md, dengan perubahan dependency dev dan panduan agen tetap belum di-commit.

## Evidence aktual

Lihat evidence/sprint1-closure/validation.json dan backend-mysql8.json.

| Pemeriksaan | Hasil | Batas interpretasi |
| --- | --- | --- |
| Laravel pada Oracle MySQL 8.0.46 / PHP 8.3.6 | 84 tes lulus, 0 skip; 233 assertions | Database uji terpisah; bukan database produksi |
| Python / model dan kontrak / evaluator | 20 tes lulus | Model privat tepercaya; label manusia belum tersedia |
| Typecheck dan production build Next.js 15.5.28 / Node 24.22.1 | Lulus | Tidak menggantikan uji browser nyata |
| Chromium / UI | 5 tes lulus; desktop 1280×900 dan mobile 375×812 | Lima invocation terpisah karena batas runtime Chromium lokal; matriks browser lain belum diuji |
| BDD HTTP dengan queue, model dan database nyata | 9 skenario lulus pada dua fase | 8 normal + 1 layanan mati; original UI features tetap checklist terpisah |
| Kapasitas 5.000 ulasan sintetis, tiga run | 7,758–8,671 detik termasuk antrean | Pola beban sintetis; bukan full dataset asli |
| Kapasitas 7.149 ulasan sintetis | 13,389 detik termasuk antrean | Seluruh hasil tersimpan dan halaman terakhir dapat diakses |
| 1.632 teks holdout dari artefak privat | 4,676 detik; 42 case | Teks/ID/rating asli, timestamp fixture; bukan CSV asli lengkap |
| Status API, 50 request lokal | p95 0,2755 detik | Satu environment lokal; bukan jaminan produksi |
| Layanan mati → restart → retry | Lulus, tanpa hasil parsial | Dua request retry dari client bersamaan memberi 202 dan 409; bukan stress test multiworker |
| Worker dihentikan SIGKILL | Recovery dan retry lulus | Timestamp umur/lease dipercepat pada database uji |
| Delivery job lama setelah attempt 2 selesai | Tidak mengubah status atau menggandakan case | Teruji pada skenario crash yang dicatat |
| Diagram | 9 sumber dan SVG berhasil dirender | Rekonstruksi teknis, tetap memerlukan review owner |
| Integrasi model CI sintetis lokal | 9 BDD HTTP + CLI kapasitas 5.000 lulus | Bukan bukti akurasi model privat; lihat ci-local-integration.json |
| CI GitHub | Workflow lima job disiapkan | Belum dijalankan di GitHub karena belum ada commit/push |

Angka pasti tiap run tercatat pada JSON evidence. Semua performa adalah pengukuran lokal.
Hasil browser terbaru dicatat pada browser-results.json; subset ini tidak menggantikan pemeriksaan seluruh matriks browser/NFR. Unduhan Chromium standar terpotong di environment lokal; runtime Chromium alternatif dipakai hanya untuk pengujian lokal, tidak dimasukkan ke repository.
Bahan privat model diverifikasi terhadap hash evidence sebelumnya, kemudian dipakai untuk tes; model dan data mentah tidak disertakan dalam patch.

## Story dan skenario penerimaan

**Login dan hak akses.** Sebagai pengguna internal, saya dapat masuk dengan credential yang valid dan memperoleh hak sesuai role.
Maintenance diarahkan ke analisis dan Developer ke placeholder backlog. Backend membatasi setiap action secara mandiri.
Field kosong, credential salah, akses tanpa sesi, role salah dan CSRF tidak valid ditolak.
Status redirect merupakan keputusan teknis D04 yang perlu disahkan pada review; placeholder bukan implementasi UC03.

**Logout.** Sebagai pengguna yang telah login, saya dapat mengakhiri sesi. Akses menggunakan sesi/cookie lama ditolak.
Bukti HTTP memeriksa sesi biasa, remember dan replay setelah logout; owner memeriksa kembali navigasi pada browser.

**UC01.** Sebagai Tim Maintenance, saya mengunggah CSV UTF-8 dan memperoleh hasil per ulasan serta kandidat Maintenance Case Pending.
File wajib memiliki reviewId, Review Teks, rating dan date. Tanggal mengikuti YYYY-MM-DD HH:MM:SS; rating 1–5.
Quoted comma/newline didukung; ID duplikat, teks kosong, tanggal/rating salah, kolom tidak lengkap dan batas file ditolak sebelum analisis.
Ulasan yang tidak menghasilkan case tetap disimpan dengan hasil analisis. Hubungan sumber case harus dapat diperiksa.
Refresh memakai batch ID pada URL; halaman hasil tidak berhenti di 200 item.
Layanan gagal tidak menghasilkan sukses palsu atau case parsial. Retry memakai batch yang sama dan attempt berikutnya.

## Keterlacakan kebutuhan

| Acuan | Implementasi / evidence | Status review |
| --- | --- | --- |
| FR-AUTH-01..04 | Login UI; field wajib; AuthTest; UI checklist di bawah | API teruji; browser dan keputusan owner dicatat terpisah |
| FR-AUTH-05..08 | AuthController, sesi/regenerasi, AuthTest dan BDD HTTP | Teruji lokal |
| FR-AUTH-09 | Redirect berdasarkan role pada UI; D04 | Perlu konfirmasi perubahan skenario SRS |
| FR-AUTH-10 | RoleMiddleware; 401/403 dan pembatasan batch | Teruji lokal |
| FR-AUTH-11..14 | Logout, invalidasi sesi dan replay cookie; smoke/BDD | Teruji HTTP, navigasi browser diperiksa owner |
| FR-UC01-01..05 | Form file, FeedbackCsv, validasi server, 422 dan file replacement | Backend teruji; lihat evidence browser dan review owner |
| FR-UC01-06..07 | Transaksi analysis_batch, ulasan dan job database | Teruji pada MySQL 8 |
| FR-UC01-08..10 | Pipeline Colab dan hasil kategori/sentimen | Runtime teruji; mutu label manusia belum disahkan |
| FR-UC01-11..14 | Baseline rule, severity, issue, grouping dan prioritas | Kode tersedia; persetujuan domain diperlukan |
| FR-UC01-15..16 | Case Pending, transaksi dan source-review mapping | Teruji lokal |
| FR-UC01-17 | Feedback berhasil diproses pada UI setelah status Selesai | Source/build tersedia; lihat evidence browser dan review owner |
| FR-UC01-18 | Rekomendasi case tersimpan dan ditampilkan | Backend/source tersedia; review relevansi oleh tim |
| NFR kapasitas 5.000 / kegagalan / integritas | Capacity JSON, crash/retry, generation guard dan tests | Kapasitas sintetis terukur; full CSV asli belum diuji |
| Artefak / BDD / traceability | docs/artifacts dan tests/bdd | Source/render/HTTP run tersedia; review owner diperlukan |

Pemeriksaan ini tidak menambah fitur Sprint 2 atau poin baru. Validasi case, edit backlog, assignment developer, GitHub App dan PR tetap mengikuti sprint berikutnya.

## Keputusan dan batas yang masih memerlukan manusia

| ID | Keputusan teknis yang disiapkan | Tindak lanjut |
| --- | --- | --- |
| D01 | Sprint 1 adalah Week 10–11; minggu kedua Week 11 | Samakan cover/stand-up laporan |
| D03 | Sesi cookie Laravel dan CSRF | Sinkronkan bagian SRS yang masih menyebut JWT |
| D04 | Maintenance ke analisis; Developer ke backlog placeholder | Sahkan redirect pada review |
| D05 | Model sentimen rating-proxy; lima rule domain draf; formula pada ANALYSIS_BASELINE.md | Dua rater, review label dan persetujuan rule |
| D06/D07 | Akun internal dan dua role | Jangan mengklaim registrasi publik/admin sebagai fitur S1 |
| Upload | Batas 10 MB desimal dan 10.000 record | Selaraskan PHP upload/post limit dan laporan |
| DoD | Kode/pengujian tidak otomatis berarti penerimaan PO | Catat pemberi keputusan, tanggal dan versi setelah review |

Sampel anotasi privat tersedia 100 baris, dengan 0 label manusia approved.
URL/pembuat/lisensi sumber data dan CSV asli lengkap masih perlu disediakan pemilik dataset.
Evaluator tidak mengisi label, menyetujui aturan atau memberi acceptance PO secara otomatis.
Poin Done tetap menunggu keputusan penerimaan yang tercatat.

## Pemeriksaan UI oleh Valisha dan Demas

1. Login dua role, invalid credential, field kosong, refresh dan logout.
2. Session expired ketika membuka hasil atau mengirim upload.
3. File non-CSV, terlalu besar, kolom salah; tombol kembali aktif sesudah file pengganti dipilih.
4. Batch Diproses/Selesai/Gagal, notifikasi dan retry.
5. Dataset >200 ulasan: halaman berikutnya/terakhir tersedia dan total count benar.
6. Buka ulasan sumber case, ganti halaman, refresh URL batch.
7. Simulasikan satu request status gagal: retry otomatis atau tombol cek status dapat memulihkan; tidak ada polling ganda.
8. Periksa ukuran desktop/mobile dan konten panjang/HTML sebagai teks.
9. Konfirmasi placeholder backlog tidak dicatat sebagai UC03 selesai.

Catat environment, hasil aktual, reviewer dan versi; checklist ini bukan hasil uji yang sudah terjadi.

## Reproduksi

Gunakan SETUP_UC01.md. Runtime lokal yang benar-benar diperiksa: PHP 8.3.6, MySQL 8.0.46, Python 3.12.14, scikit-learn 1.6.1, Node 24.22.1.
Python 3.13 tercatat pada evidence sebelumnya. Dependency ML dan versi schema generator harus sesuai requirements.

    cd apps/api
    composer install
    php artisan test --compact
    php artisan analysis:recover-stale
    php artisan schedule:work

Gunakan database test tersendiri untuk suite MySQL; jangan arahkan RefreshDatabase ke database demo/produksi.
Recovery default 300 detik harus melebihi timeout/lease; antrean yang belum mulai diperiksa dengan menyalakan worker.

    cd services/analysis
    python -m pip install -r requirements-dev.txt
    python -m pytest -q tests

Tes model membutuhkan UC01_ARTIFACT_DIR yang memuat model/slang/rule privat tepercaya.
CI publik memiliki lima job: MySQL, kontrak/evaluator, frontend, FastAPI dengan model sintetis, serta integrasi BDD/browser/kapasitas sintetis. Model sintetis CI bukan bukti mutu model asli. Workflow baru belum dijalankan di GitHub sebelum push.

    python scripts/export_analysis_contracts.py --check
    python -m behave tests/bdd/api_sprint1.feature --tags="not @service_down" --format json --outfile tmp/bdd-http.json
    python scripts/benchmark_uc01.py --synthetic-rows 5000 --output tmp/capacity-5000.json
    python scripts/benchmark_uc01.py --csv data/raw/Review.csv --output tmp/source-capacity.json

Jalankan BDD layanan mati hanya pada environment disposable sesuai tests/bdd/README.md.
Benchmark source CSV tidak menyatakan identitas dataset atau akurasi manusia secara otomatis.

    cd apps/web
    npm ci
    npm run typecheck
    npm run build
    npx playwright install chromium
    npm run test:e2e

## Review, commit dan assignment

Pengguna melakukan review patch, commit dan push melalui branch fix/sprint1-completion. Issue #12–16 sudah ada beserta assignee; lihat [handoff](SPRINT1_HANDOFF.md). Akses tulis integrasi ChatGPT ditolak 403, sehingga paket ini belum dipublikasikan. Perubahan tim pada PR #17 dan #18 dipertahankan.
Gerard menutup koordinasi/UC01/domain/PO; Demas auth/DB/persistensi; Hanif kontrak/recovery/BDD; Valisha UI/artefak.
Hasil implementasi berbantuan AI tidak menghapus atribusi source tim sebelumnya.
Nama dan kewenangan penerima fitur tetap diisi berdasarkan review nyata.

Setelah patch di-commit, ganti penanda working tree dengan SHA commit milik pengguna pada evidence/review log.
Issue boleh ditutup setelah kriterianya terpenuhi. Jangan menutup issue evaluasi manusia atau acceptance hanya karena file kode sudah tersedia.
