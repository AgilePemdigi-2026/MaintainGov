# Decision Log — MaintainGov

## D-W11-01 — Terminologi JWT vs Sesi di SRS
Tanggal: 2026-10-31 | Owner: Demas
Keputusan: implementasi memakai session cookie HTTP-only (SRS §3.4.4);
penyebutan JWT di SRS §5.3 diperlakukan sebagai errata yang disinkronkan saat UAS.
Status: tercatat, menunggu sinkronisasi dokumen.

## D-W11-02 — Kebijakan deduplikasi file (Idempotency-Key)
Tanggal: 2026-10-31 | Owner: Demas
Keputusan: Idempotency-Key default = sha256 file; key sama file beda => 409;
key sama file sama => replay 202 tanpa job ganda.
Status: berlaku.

## D-W12-02-01 — priority_score vs override manual pada backlog
Tanggal: 2026-11-01 | Owner: Demas / Gerard
Keputusan: menunggu PO. Opsi A (rekomendasi backend): priority_score hasil ML
immutable setelah backlog terbentuk; PATCH UC03 hanya mengubah priority enum.
Opsi B (implementasi interim): PATCH menulis bucket High=9.00/Medium=5.50/Low=2.00;
wajib tercantum di kontrak UC03 dan delta selama belum diputus.
Status: MENUNGGU PO; interim Opsi B dengan penanda TODO(decision D-W12-02-01) di kode.

## D-W14-01-a — Role dan identitas reviewer UC06 (SRS, bukan default)
Tanggal: 2026-11-14 | Owner: Demas | Reviewer: Hanif
Keputusan: reviewer sah = role `Developer` saja, KECUALI penulis PR
(penulis = `pull_request.developer_id`, yaitu assignee backlog saat pengajuan).
Default W14-01-a ("Developer dan Tim Maintenance") TIDAK dipakai karena SRS tidak
diam: SRS §4.8.2 menetapkan aktor UC06 = Tim Developer (glosarium: Tim Developer
bertanggung jawab atas Code Review; Tim Maintenance tidak), dan ARCHITECTURE.md
§2 menyatakan review PR untuk Tim Maintenance = Tidak. Self-review juga ditolak
GitHub (penulis tidak dapat menyetujui PR miliknya sendiri).
Status: berlaku (kontrak uc06-api.md §2); bila PO menghendaki Tim Maintenance
dapat mereview, cukup ubah middleware role — kontrak diperbarui saat itu.

## D-W14-01-b — Efek Approve dan Request Changes pada status
Tanggal: 2026-11-14 | Owner: Demas
Keputusan (berbasis BR-19..22 + FR-UC06-12..16, bukan default murni):
- Request Changes tersinkron → PR `review_state=changes_requested` (status
  `Changes Requested`) dan backlog kembali `Sedang Dikerjakan` (BR-20, FR-UC06-14).
- Approve tersinkron → PR `review_state=approved` (status `Approved`);
  `Ready for Merge` adalah label tampilan (`ready_for_merge=true`), bukan enum
  kolom — menutup deferred "ENUM Ready for Merge" pdm-delta-sprint1/2 (BR-21,
  FR-UC06-12: PR Approved + tampil Ready for Merge).
- Backlog `Selesai` HANYA saat sinkronisasi GitHub melaporkan PR `merged`
  (BR-22); approve saja tidak pernah menyelesaikan backlog. Mekanisme sync
  merged (FR-UC06-16) di luar W14-01.
Status: berlaku; tercantum di pdm-delta-sprint3.md dan kontrak §3.

## D-W14-01-c — Satu review aktif per reviewer
Tanggal: 2026-11-14 | Owner: Demas
Keputusan: UNIQUE(pull_request_id, reviewer_id) pada `code_review`; review baru
dari reviewer sama menggantikan baris lama (decision/comment/reviewed_at/field
sync di-upsert). Model sesuai default W14-01-c; fungsi utamanya mendukung retry
sinkronisasi saat PR masih Open.
Status: berlaku (kontrak §4.2/§4.3, delta W14-01).

## D-W14-01-d — Review hanya saat PR Open
Tanggal: 2026-11-14 | Owner: Demas
Keputusan: POST review diterima hanya saat PR berstatus Open
(`submission_state=created`, `lifecycle_state=open`, `review_state=pending`),
selain itu 409 PULL_REQUEST_NOT_OPEN (sesuai default W14-01-d; juga konsisten
dengan precondition SRS UC06 "Pull Request berstatus Open telah tersedia").
Alur revisi: Request Changes → backlog Sedang Dikerjakan → push ulang → PR baru
(head SHA baru) direview pada PR barunya (BR-18/BR-20).
Status: berlaku (kontrak §3 langkah 5).

## D-W14-01-e — Kegagalan sinkronisasi review bukan sukses palsu
Tanggal: 2026-11-14 | Owner: Demas
Keputusan (pola UC05 "GitHub owns the side effect"; FR-UC06-12/13: transisi
status hanya setelah tersinkron): review disimpan dulu (`sync_status=pending`),
GitHub dipanggil di luar transaksi; sukses → transaksi kedua menulis
`synced` + transisi status; gagal → `sync_status=gagal` + `last_sync_error_code`,
PR tetap Open, response 200 dengan peringatan `meta.sync` dan pesan
"Review tersimpan tetapi belum tersinkron ke GitHub". Reviewer dapat submit
ulang (upsert D-W14-01-c). Transaksi kedua re-check `review_state` di bawah
lock; bila keputusan lain telah tersinkron lebih dulu → `gagal`
(REVIEW_SUPERSEDED) tanpa transisi ganda.
Status: berlaku (kontrak §4.3 langkah 5–7).

## D-W14-01-f — Nama tabel review mengikuti SRS: `code_review`
Tanggal: 2026-11-14 | Owner: Demas | Reviewer: Hanif
Keputusan: tabel review dinamai `code_review` (bukan `pull_request_reviews`)
karena SRS data mapping §4.8.4 dan glosarium menamai entitas ini Code Review,
dan konvensi repo mempertahankan nama SRS (pengguna_sistem, maintenance_case,
ulasan). Struktur kolom mengikuti usulan W14-01: review_id PK, pull_request_id
FK RESTRICT, reviewer_id FK RESTRICT, decision ENUM(Approved, Changes
Requested), comment TEXT NULL, reviewed_at, timestamps + kolom sinkron.
Status: berlaku; tercatat di pdm-delta-sprint3.md.

## D-W14-01-g — Akses baca endpoint UC06
Tanggal: 2026-11-14 | Owner: Demas
Keputusan: list/detail/review PR tetap Developer-only (mempertahankan kontrak
teruji UC05 §4.3 dan aktor SRS UC06). Endpoint riwayat
`GET /maintenance-cases/{case}/history` dibuka untuk Tim Maintenance dan
Developer (read-only) karena fitur Status dan Riwayat dipakai kedua role;
SRS tidak mengatur endpoint ini (default: kedua role baca, konsisten dengan
matriks baca ARCHITECTURE §2).
Status: berlaku (kontrak §2 dan §4.4).