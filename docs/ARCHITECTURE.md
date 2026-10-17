# Arsitektur MaintainGov

Rancangan berikut menjelaskan hubungan web, backend, layanan analisis, database, dan GitHub. Kebutuhan dasarnya mengikuti SRS. Endpoint dan perubahan tabel perlu diperiksa saat fitur terkait mulai dikerjakan. Catatan keputusan ada di [Rencana Kerja](MASTERPLAN.md).

## 1. Komponen dan batas tanggung jawab

```text
Browser pengguna
     |
     v
Next.js + TypeScript
     | REST JSON / multipart; session cookie + CSRF
     v
Laravel API ------------------------------> GitHub REST API
     |                                      ^
     | MySQL                                | webhook terverifikasi
     v                                      |
Database <---- Laravel worker / adapter -----+
                         |
                         | internal REST JSON
                         v
                   Python + FastAPI
                   analisis feedback
```

| Komponen | Tanggung jawab | Pemilik teknis awal |
| --- | --- | --- |
| Web | Halaman sesuai SRS, navigasi role, unggah file, status proses, hasil, pesan kesalahan | Valisha; pemilik fitur ikut mengimplementasikan |
| Laravel | Auth/RBAC, validasi, transaksi, workflow, persistensi, orkestrasi layanan | Demas; Hanif untuk adapter/integrasi |
| Worker | Memproses analysis batch di luar request browser, menyimpan kegagalan dan hasil | Hanif + Demas |
| FastAPI | Preprocessing, kategori, sentimen, severity, ekstraksi, grouping, prioritas, rekomendasi | Gerard |
| MySQL | Seluruh data bisnis dan relasi asal feedback sampai review | Demas; migration fitur dikerjakan pemilik fitur dengan review |
| GitHub adapter | Repository, branch/event, PR, review, sinkronisasi; kredensial di backend | Hanif; Demas mendukung UC06 |

Frontend berkomunikasi dengan Laravel; tidak memanggil FastAPI, MySQL, atau GitHub langsung. FastAPI mengembalikan data analisis dan tidak menulis database bisnis. Laravel memutuskan kapan batch sukses dan kapan case terbentuk.

Kode dan artefak proyek dikelola di **[AgilePemdigi-2026/MaintainGov](https://github.com/AgilePemdigi-2026/MaintainGov)**. Integrasi GitHub pada produk untuk UC04-UC06 tetap memerlukan konfigurasi dan pengujian tersendiri.

```text
MaintainGov/
  apps/web/                     Next.js + TypeScript
  apps/api/                     Laravel + migration + worker
  services/analysis/             FastAPI + baseline NLP + tests
  contracts/                    OpenAPI + JSON Schema + contoh payload
  docs/                         acuan tim, tracker dan referensi
  docs/artifacts/               sumber PlantUML dan artefak per fitur
  docs/evidence/                ringkasan uji dan bukti tanpa rahasia
  data/fixtures/                contoh kecil dengan identitas disamarkan
  data/raw/                     bahan mentah lokal, diabaikan Git
  tests/bdd/                    skenario + penghubung ke runner aktual
  scripts/                      setup/pemeriksaan yang dapat diulang
  .github/                      template issue dan PR; workflow setelah stack siap
```

Struktur di atas merupakan target pengembangan: tiga aplikasi terpisah sesuai SRS, dengan satu backend modular. Versi runtime dan dependensi ditentukan pada W10-02 setelah diperiksa di mesin anggota, lalu dikunci melalui lockfile.

## 2. Autentikasi dan otorisasi

Autentikasi direncanakan menggunakan **sesi cookie Laravel** dan CSRF, misalnya melalui Sanctum SPA. Pilihan ini mengikuti SRS §3.4.4; bagian lain yang masih menyebut JWT perlu diselaraskan. Rujukan teknis: [dokumentasi Laravel](https://laravel.com/framework/docs/13.x/sanctum), disesuaikan dengan versi yang dipilih.

- Seed akun internal untuk `Tim Maintenance` dan `Developer`; tidak menambah register publik di Sprint 1.
- Cookie identitas sesi: HttpOnly; Secure pada HTTPS produksi; SameSite dan domain disesuaikan dengan topologi deployment. Token CSRF berbeda dari cookie identitas sesi.
- Backend memverifikasi login dan role untuk setiap endpoint. UI hanya membantu navigasi; menyembunyikan tombol bukan kontrol akses.
- Login merotasi sesi; logout membatalkan sesi dan token CSRF yang relevan. Session lama harus gagal dipakai ulang.
- Password di-hash. Kunci GitHub/token yang perlu dipakai kembali disimpan sebagai secret/enkripsi yang sesuai, bukan di-hash lalu tidak dapat dipakai lagi.
- Untuk uji lokal, samakan hostname web/API, dokumentasikan origin/port, pengiriman credentials dan CSRF. Produksi memakai HTTPS dan domain yang sesuai untuk session cookie.

| Operasi | Maintenance | Developer |
| --- | --- | --- |
| Login/logout dan identitas sendiri | Ya | Ya |
| Upload/analisis, validasi case, edit backlog | Ya | Tidak |
| Membaca backlog untuk pengerjaan | Ya sesuai kebutuhan UC03 | Ya |
| Klaim issue, pengajuan PR, review PR | Tidak | Ya, sesuai assignment dan identitas reviewer |

Usulan redirect role: Maintenance ke `/analisis-feedback`, Developer ke `/maintenance-backlog`. Ini memerlukan sinkronisasi FR-AUTH-09 dan skenario login yang masih mengarahkan semua pengguna ke analisis (D04). Jangan mengubah aturan akses agar redirect lama tampak berhasil.

## 3. Alur UC01 yang menjadi prioritas Sprint 1

1. Pengguna Maintenance memilih CSV. Web memeriksa ekstensi/ukuran dan meminta validasi struktur ke Laravel; backend tetap memvalidasi ulang pada submit.
2. Laravel membaca CSV dengan parser yang mendukung quoted fields, bukan pemisahan string berdasarkan koma. Pemetaan dataset aktual ada pada [Dataset Readiness](DATASET_READINESS.md).
3. File salah/kolom kurang: HTTP 422, pesan `Struktur CSV tidak sesuai` dan daftar kolom wajib; belum ada pemanggilan analisis atau case palsu. Web mengaktifkan kembali Analisis setelah file baru lolos validasi.
4. File valid: Laravel menyimpan `analysis_batch` berstatus `Diproses`, ulasan, dan permintaan pemrosesan yang dapat dipulihkan; browser mendapat HTTP 202 dan `batch_id`.
5. Worker mengambil batch setelah transaksi tersimpan. Usulan awal: queue database Laravel agar tidak menambah dependensi Redis. Proses API dan worker harus sama-sama dijalankan dan dicek.
6. Worker memanggil FastAPI internal dengan daftar ulasan dan ID referensi. Layanan mengembalikan hasil per ulasan dan kelompok case; request browser tidak menunggu proses NLP selesai.
7. Laravel memvalidasi kelengkapan output serta ID; menyimpan hasil, relasi ulasan-case, dan case `Pending` dalam transaksi. Hanya setelah commit, status batch menjadi `Selesai`.
8. Web melakukan polling status, misalnya tiap 3 detik, berhenti pada `Selesai`/`Gagal` dan saat halaman ditutup. Selesai menampilkan hasil dan `Feedback berhasil diproses`.
9. Timeout/layanan gagal/output tidak valid: catat error, tandai batch `Gagal`; jangan tampilkan sukses atau case sebagian. Retry memakai batch yang sama dan upsert/kunci idempotensi agar case tidak ganda.

Atur timeout HTTP internal, timeout worker, lease/retry queue dan jumlah retry secara konsisten berdasarkan pengukuran. Timeout browser biasa tidak boleh memotong pekerjaan analisis. Queue gagal dipublikasikan/worker mati harus terlihat; tambahkan prosedur memulihkan batch tertahan. Simpan attempt, waktu mulai/selesai, dan error ringkas. Detail exception/rahasia tidak dikirim ke browser.

## 4. Kontrak API awal

**Draf kontrak v0.1.** Hanif menulis OpenAPI/JSON Schema di `contracts/` sebelum web dan backend dikembangkan terpisah. Semua endpoint bisnis berawalan `/api/v1`. Endpoint CSRF mengikuti framework. Route halaman UI tetap mengikuti SRS dan dibedakan dari endpoint API.

| Method/path | Input/output inti | Akses | Penerapan |
| --- | --- | --- | --- |
| POST `/auth/login` | email/password -> identitas, role; session cookie | Publik, dibatasi percobaannya | Sprint 1 |
| GET `/auth/me` | identitas dan role sesi aktif | Kedua role | Sprint 1 |
| POST `/auth/logout` | batalkan sesi; respons 204 | Kedua role | Sprint 1 |
| POST `/feedback/validate` | multipart `file` -> kolom, jumlah baris, daftar error | Maintenance | Sprint 1 |
| POST `/analysis-batches` | multipart `file`, request key -> batch_id/status (202) | Maintenance | Sprint 1 |
| GET `/analysis-batches/{id}` | status, jumlah ulasan/case, waktu dan error aman | Maintenance | Sprint 1 |
| POST `/analysis-batches/{id}/retry` | ulang batch gagal, tanpa case duplikat | Maintenance | Sprint 1 |
| GET `/maintenance-cases` | daftar, filter/paginasi, hasil ringkas | Maintenance | Sprint 1 untuk hasil; Sprint 2 untuk validasi |
| GET `/maintenance-cases/{id}` | detail dan ulasan pembentuk | Maintenance | Sprint 1 untuk hasil |
| POST `/maintenance-cases/{id}/validation` | valid/tidak valid -> case dan backlog bila valid | Maintenance | Sprint 2 |
| GET/PATCH `/maintenance-backlogs/{id}` | baca/edit field yang diizinkan saat belum dikerjakan | Sesuai role dan aksi | Sprint 2 |
| POST `/maintenance-backlogs/{id}/claim` | klaim atomik oleh Developer | Developer | Sprint 2 |
| POST `/repositories` | tautkan repository terpilih melalui GitHub App | Role konfigurasi: putuskan D09 | Sprint 2; uji awal Week 10 |
| POST `/maintenance-backlogs/{id}/pull-requests` | branch tujuan, judul, deskripsi -> metadata PR nyata | Developer penerima pekerjaan | Sprint 2 |
| POST `/pull-requests/{id}/reviews` | approve/request changes, catatan -> hasil GitHub terverifikasi | Developer reviewer yang sesuai | Sprint 3 |
| POST `/webhooks/github` | event + signature + delivery ID | Signature GitHub, bukan session browser | Sprint 2; rancangan Week 10 |

Prefix `/api/v1` diterapkan pada path dalam tabel selain endpoint framework yang terdokumentasi terpisah. Daftar/detail PR, riwayat, dan paginasi backlog dilengkapi saat meninjau kebutuhan Sprint 2; jangan menganggap tabel ini kontrak final seluruh aplikasi.

Contoh respons batch diterima (contoh fiktif untuk kontrak):

```json
{
  "data": {
    "batch_id": 101,
    "status": "Diproses",
    "review_count": 50,
    "started_at": null,
    "completed_at": null
  }
}
```

Contoh kontrak internal Laravel -> FastAPI `POST /internal/v1/analyze`:

```json
{
  "schema_version": "1.0",
  "batch_id": 101,
  "reviews": [{"review_id": 501, "text": "Tidak dapat masuk setelah pembaruan", "rating": 1}]
}
```

Respons internal contoh: `schema_version`, `analysis_version`, `batch_id`, `results[]` berisi review_id/kategori/sentimen/severity/issue/modul/fitur, dan `cases[]` berisi group_key/member_review_ids/judul/ringkasan/recommendation/priority_score. Taxonomy, aturan tidak-ada-issue, dan formula dibekukan dalam W10-03. Jangan menjadikan nama pengguna sebagai input wajib NLP.

Respons error konsisten: `error.code`, `error.message`, `error.fields`, `request_id`. Gunakan 401 tanpa sesi, 403 role tidak berhak, 409 konflik status/klaim, 422 input/struktur salah, 502/503 kegagalan eksternal sesuai konteks. Kesalahan pekerjaan asinkron juga terlihat pada status batch, bukan hanya kode request awal.

## 5. Data dan perubahan PDM

Nama utama dipertahankan dari SRS: `pengguna_sistem`, `analysis_batch`, `ulasan`, `maintenance_case`, `maintenance_backlog`, `github_repository`, `pull_request`, `code_review`. `Maintenance Issue` adalah backlog dalam konteks pengerjaan, **bukan tabel baru**, sesuai glossary SRS hlm. PDF 65.

Relasi: pengguna mengunggah batch; batch memiliki banyak ulasan; case mengonsolidasikan banyak ulasan; satu case valid menghasilkan paling banyak satu backlog utama; repository memiliki banyak backlog; backlog memiliki banyak PR; PR memiliki banyak review dengan reviewer dan waktu. Asal batch case dapat ditelusuri lewat ulasan; bila menambah FK langsung, dokumentasikan konsistensinya.

| Perubahan yang perlu diperiksa | Alasan / acuan | Waktu |
| --- | --- | --- |
| `analysis_batch.started_at`, `completed_at`, error/attempt metadata dan versi analisis | NFR-PERF-10 dan pemulihan proses; PDM menampilkan tanggal analisis tunggal | Sprint 1 |
| Hasil kategori/sentimen/severity/issue per ulasan, lewat kolom tambahan atau tabel hasil berversi | Pipeline menghasilkan output per ulasan yang belum seluruhnya tampak pada PDM | Sprint 1 |
| `ulasan.source_review_id`, unique per batch; field feedback sumber dipetakan eksplisit | Dataset mempunyai reviewId, sedangkan PDM memakai review_id internal | Sprint 1 |
| `ulasan.case_id` boleh kosong selama Diproses/Gagal dan ketika belum terbentuk case | Ulasan harus tersimpan sebelum hasil analisis tersedia | Sprint 1 |
| `maintenance_case.user_id` untuk validator belum terisi saat Pending; bedakan dari pengunggah batch | Hindari menganggap pengunggah sebagai orang yang sudah memvalidasi | Sprint 1-2 |
| Unique pada `maintenance_backlog.case_id` | BR-10: satu backlog utama per case valid | Sprint 2 |
| `maintenance_backlog.assigned_to`, `assigned_at`, `source_branch` | FR-UC04-07 dan FR-UC05-02; SRS sendiri menandai assigned_to belum dikonfirmasi PDM | Sebelum UC04 |
| Enum/status Ready for Merge dan status PR Closed yang berbeda dari Merged | PDM backlog tidak menampilkan Ready for Merge; event GitHub closed dapat tidak merged | Sebelum UC06 |
| GitHub numeric IDs, reviewer GitHub identity mapping, delivery IDs, latest head SHA dan sinkronisasi | Cegah atribusi/retry salah dan review pada revisi usang | Uji awal Sprint 1; migration Sprint 2-3 |
| Riwayat perubahan status dengan actor, timestamp, sumber event dan ID objek | Mendukung fitur riwayat dan penelusuran data yang PDM awal belum menyimpan lengkap | Sprint 2-3 |

Nama field tambahan adalah rancangan, bukan fakta skema yang sudah berjalan. Demas mengajukan revisi PDM/Class beserta migration; reviewer memastikan semua FK, nullability, unique constraint dan enum sesuai workflow. Jangan menambah semua tabel fitur masa depan pada Week 10.

## 6. Status dan aturan perubahan

| Objek | Alur baseline | Aturan |
| --- | --- | --- |
| Analysis Batch | Diproses -> Selesai atau Gagal; retry Gagal -> Diproses | Selesai hanya setelah hasil dan case yang valid tersimpan; zero actionable issue ditangani sesuai keputusan W10-03 |
| Maintenance Case | Pending -> Valid atau Tidak Valid | Hanya Maintenance; konfirmasi keputusan; tidak valid tidak membentuk backlog |
| Backlog/Issue | Ready for Development -> Sedang Dikerjakan -> Ready for Pull Request; review disetujui -> Ready for Merge; perubahan diminta -> Sedang Dikerjakan | Klaim atomik; status Ready for Merge diselaraskan dengan PDM; kondisi Selesai perlu penerimaan PO |
| Pull Request | Open -> Approved atau Changes Requested -> revisi/review ulang; merge eksternal -> Merged | Approved/Changes Requested adalah status review aplikasi; pisahkan dari status open/closed/merged GitHub |

Mapping usulan: `pull_request.lifecycle_state = open/closed/merged` dan `review_state = pending/approved/changes_requested`; label UI mengikuti istilah SRS. Model review ulang dan invalidasi approval pada commit baru harus diputuskan sebelum UC06 dianggap Done. Jangan menganggap satu event approve berarti semua aturan branch GitHub sudah terpenuhi.

SRS BR-22: MaintainGov tidak menjalankan merge atau deployment. Developer melakukan merge di GitHub, kemudian aplikasi menyinkronkan status. Refresh halaman hanya membaca status; perubahan status karena push harus didahului event GitHub yang terverifikasi, sesuai FR-UC04-09/10. Push tidak membuktikan pengujian lokal lulus; tampilkan hasil checks yang memang tersedia pada UC06.

## 7. Metode analisis awal

Dataset berisi ulasan, kamus sentimen, dan kamus slang. Label kategori, severity, serta kelompok issue belum tersedia. Analisis awal dapat memakai aturan sederhana sesuai pilihan pada SRS Appendix C. Setelah alurnya berjalan dan ada hasil evaluasi, metode tersebut bisa diperbaiki atau dibandingkan dengan model lain.

| Tahap | Rancangan awal | Bukti yang wajib disiapkan Gerard |
| --- | --- | --- |
| Preprocessing | Pertahankan teks asli, normalisasi Unicode/spasi dan slang dengan aturan konflik eksplisit; jangan menghapus negasi atau bukti error | Contoh sebelum/sesudah, versi aturan |
| Kategori | Taxonomy maintenance kecil, misalnya defect/performance/usability/request/other; final mengikuti review domain | Definisi label dan contoh yang disepakati |
| Sentimen | Lexicon dengan bobot, negasi, aturan istilah overlap dan multiword | Skenario positif/negatif/netral/ambigu; hasil audit kamus |
| Severity | Aturan dampak layanan seperti gagal masuk/transaksi/error berulang, terpisah dari rating dan sentimen | Alasan setiap label dan contoh kasus berbeda dampak |
| Ekstraksi/grouping | Kandidat issue/modul/fitur dari teks; similarity/rules yang dikonfigurasi; pujian umum tidak otomatis menjadi bug | Contoh harus digabung/dipisah dan tautan member_review_ids |
| Prioritas | Formula transparan yang memperhitungkan dampak dan frekuensi; tetapkan skala/bobot/tie-breaker setelah data ditinjau | Dokumen formula berversi, contoh perhitungan, sensitivity check |
| Rekomendasi | Rekomendasi kontekstual berdasarkan issue; tandai sebagai saran yang perlu validasi | Hasil terkait sumber dan tidak mengaku sebagai solusi teruji |

Untuk evaluasi Week 10, pilih 50-100 ulasan dengan variasi rating, panjang teks, waktu, dan masalah. Gerard memberi label, lalu anggota lain memeriksa sebagian hasil dan membahas perbedaannya. Pisahkan data evaluasi dari data untuk menyusun aturan. Catat hasil tiap label dan contoh kesalahannya; batas kualitas dibahas bersama PO. Rating bintang tidak langsung dianggap sebagai label yang benar.

Pujian atau teks tanpa masalah tetap memiliki hasil analisis, tetapi tidak otomatis menjadi pekerjaan maintenance. Ketentuan pembentukan case untuk hasil non-actionable harus ditutup bersama PO pada W10-03; jangan diam-diam membuat backlog dari semua ulasan.

## 8. Uji koneksi GitHub pada Week 10

Pisahkan **repo untuk mengembangkan MaintainGov** dari **repo sandbox yang dihubungkan ke produk MaintainGov**. Keduanya bisa berada dalam organisasi yang sama; sandbox menghindari tercampurnya PR latihan integrasi dan PR implementasi kelompok.

Hanif mencoba pemasangan GitHub App pada repository uji, lalu memeriksa izin metadata/contents, pembuatan PR, dan review. Event push/PR/review harus diterima dengan signature yang benar; branch sumber juga perlu terhubung ke backlog. Catat langkah, hasil, dan kendalanya. UI koneksi lengkap dikerjakan pada Sprint 2.

Identitas pengaju dan reviewer harus dibedakan. Token instalasi mewakili aplikasi; bila aksi perlu atas nama pengguna, rancang GitHub App user access token dan pemetaan pengguna internal ke GitHub. Lihat [autentikasi atas nama pengguna](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/authenticating-with-a-github-app-on-behalf-of-a-user). Uji dua akun karena [penulis PR tidak dapat menyetujui PR miliknya sendiri](https://docs.github.com/en/pull-requests/how-tos/review-pull-requests/approving-a-pull-request-with-required-reviews).

Event webhook: verifikasi signature, repository, branch/PR terkait; deduplikasi delivery ID; tangani pengiriman ulang dan urutan terlambat. Kegagalan create PR setelah timeout memerlukan pencarian PR yang sudah terbentuk sebelum retry (NFR-SAF-09). Jangan menandai operasi berhasil sebelum GitHub mengonfirmasi.

## 9. Kualitas dan pengujian bertahap

- Sprint 1: unit analisis/CSV; API auth/RBAC/logout; integrasi Laravel-FastAPI-database; alur browser nyata valid/invalid/service-down; sumber BDD dan hasil run.
- Sprint 2: transaksi case->backlog sekali; edit sebelum assignment; dua developer klaim bersamaan hanya satu berhasil; push terverifikasi; create PR sukses/gagal/timeout/retry tanpa duplikasi.
- Sprint 3: review dua identitas, request changes, revisi dan approval; sinkronisasi merged; penelusuran data; UAT; kesiapan backup/restore dan lingkungan demonstrasi.

Target performa SRS: halaman utama p95 <=3 detik; API internal biasa p95 <=2 detik; validasi CSV <=5 detik; analisis 5.000 ulasan ditargetkan <=120 detik, tidak termasuk antrean. Batas upload diusulkan diterapkan sebagai **kedua batas**: ukuran <=10 MB dan jumlah <=10.000 baris; revisi bila PO menafsirkan kalimat SRS berbeda. Batch penuh dataset berisi 7.149 ulasan; target 120 detik hanya dinyatakan untuk 5.000, bukan otomatis untuk seluruh dataset.

Uji kecil 50-100 ulasan pada Week 10 adalah pemeriksaan alur, bukan bukti kapasitas 5.000/10.000. Rencanakan pengukuran 5.000 dan seluruh dataset pada Week 11 setelah metode stabil; catat lingkungan, waktu queue, preprocessing, analisis dan persistensi. NFR lain seperti 25 pengguna bersamaan, backup harian/RPO 24 jam/RTO 4 jam, browser compatibility, dan uptime bulanan tetap ditelusuri di tracker; jangan mengklaimnya terpenuhi dari demo singkat.
