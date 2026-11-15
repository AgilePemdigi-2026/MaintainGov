# W14-03 — Frontend UC06 Review Pull Request + Status/Riwayat Maintenance

Tanggal: 2026-11-15 | PIC: Valisha (frontend) | Reviewer: Gerard (belum) <br>
Branch: `feature/W14-03-frontend-uc06-riwayat` <br>
Acuan: [contracts/uc06-api.md](../../../contracts/uc06-api.md) §4–§5 (backend W14-01, sudah di `main`), SRS UC06 FR-UC06-01..15, BR-18..23, issue #25/#26.

## 1. Yang berubah

| Area | Halaman / berkas | Isi |
| --- | --- | --- |
| UC06 daftar PR | `/pull-request` | Tabel PR + badge `review_status` (Ready for Merge saat `ready_for_merge`), filter `?status=`, paginasi, refresh, loading/empty/error. |
| UC06 detail + review | `/pull-request/[id]` | Info PR (author, repo, branch, deskripsi), issue + case terkait, perubahan kode (head/base SHA + daftar berkas dari GitHub publik, fallback ke "Files changed"), riwayat review + status sinkron. Approve, Request Changes (komentar wajib, maks 5000), pesan FR-UC06-15 persis, peringatan sinkron gagal + **Kirim ulang**. |
| Status | `/riwayat` | Status setiap backlog (filter, cari) + buka riwayat langsung dengan ID case. |
| Riwayat | `/riwayat/[caseId]` | Stepper Feedback → Case → Backlog → Issue → Pull Request → Review, timeline kronologis (aktor, waktu, detail per tipe), ringkasan PR, ulasan asal (khusus Tim Maintenance). |
| Navigasi | `app-shell.tsx` | Menu "Pull Request" (Developer), "Status & Riwayat" (kedua role). |
| UC03–UC05 | `sprint2/client.ts`, halaman issue/backlog/case | PR dengan Changes Requested tidak lagi mengunci pengajuan PR revisi (BR-18); detail issue menautkan ke review PR + status review; tautan Riwayat dari case, backlog, issue. |
| API client | `src/lib/sprint3/*` | Tipe, client, label UC06 sesuai kontrak; tidak ada mock UC06. |

Hak akses mengikuti kontrak (D-W14-01-a/g): halaman PR hanya Developer; tombol review hanya muncul bila user **bukan penulis PR** dan PR masih `Open`; Tim Maintenance mendapat "Akses ditolak" + menu PR tersembunyi. Backend tetap menjadi penjaga akhir (403/409).

## 2. Hasil pemeriksaan

| Pemeriksaan | Hasil |
| --- | --- |
| `npm run typecheck` | Lulus |
| `npm run build` | Lulus (route baru: `/pull-request`, `/pull-request/[id]`, `/riwayat`, `/riwayat/[caseId]`) |
| Playwright `sprint3-review.spec.ts` (kontrak, Laravel dicegat) | **7/7 lulus** — list+filter, detail, Request Changes wajib komentar + pesan FR-UC06-15, sinkron gagal → Kirim ulang → Ready for Merge, penulis/non-Open tanpa tombol, Tim Maintenance ditolak, riwayat + feedback asal |
| Playwright `sprint3-live.spec.ts` (Laravel **nyata**, tanpa intersep) | **2/2 lulus** — reviewer: list, detail, reload, riwayat, mobile 390px tanpa scroll horizontal; Tim Maintenance: status, riwayat + feedback asal, akses PR ditolak |
| Playwright Sprint 2 (case, backlog, issue-pr, live) | Lulus (live di-skip by design tanpa env) |
| Playwright `sprint1.spec.ts` | 4 gagal — **sudah gagal di `main` sebelum perubahan ini** (5 gagal di `main`, heading `Batch # … Selesai` halaman analisis). Tidak terkait W14-03; perlu issue terpisah. |

## 3. Alur browser nyata (manual, 15 Nov 2026)

Stack: Laravel `main` (`php artisan serve`) + Next.js production build + browser, database SQLite lokal (MySQL lokal butuh kredensial root). Data: 3 case fixture tersamar.

1. Tim Maintenance memvalidasi case 1 → backlog BKLG-0001 (API UC02).
2. Developer klaim → verifikasi push → ajukan PR #9001.
3. Reviewer (Developer lain) membuka `/pull-request/1`: Request Changes kosong → "Komentar wajib diisi…", tidak ada request terkirim; dengan komentar → **"Perubahan memerlukan perbaikan."**, review tersinkron, tombol terkunci.
4. Developer: issue kembali **Sedang dikerjakan**; push baru (`7a29ded`) diverifikasi → form PR **tidak** terkunci oleh PR lama → PR revisi #9002 dibuat. Penulis melihat "Anda adalah penulis Pull Request ini…".
5. Reviewer Approve saat GitHub 503 → peringatan "Review tersimpan tetapi belum tersinkron…", status tetap Open; GitHub pulih → **Kirim ulang** → **Ready for Merge**.
6. `/riwayat/1`: 9 event dalam urutan benar, dua PR (Changes Requested → Ready for Merge), semua review "Tersinkron ke GitHub".

Screenshot: [01 daftar PR](01-pull-request-list.png) · [02 detail PR](02-pull-request-detail.png) · [03 riwayat Developer](03-riwayat-developer.png) · [04 riwayat Tim Maintenance](04-riwayat-maintenance.png) · [05 mobile detail](05-mobile-pull-request-detail.png) · [06 mobile riwayat](06-mobile-riwayat.png) · [07 status](07-status-maintenance.png).

## 4. Batas verifikasi (jujur)

- **GitHub bukan GitHub asli.** Token belum tersedia (sama dengan W14-01 §6), jadi Laravel diarahkan ke server tiruan lokal lewat `GITHUB_API_URL` yang meniru endpoint repo/branch/commit/pulls/reviews. Nomor PR #9001/#9002 di screenshot **tidak ada** di GitHub. Live smoke dengan token tetap BLOCKED.
- Daftar berkas berubah dibaca browser dari `api.github.com` publik tanpa token. Untuk PR tiruan hasilnya fallback "tidak dapat dibaca" (terlihat di screenshot 02); repo privat/rate limit juga jatuh ke tautan "Files changed".
- Diuji pada SQLite, belum MySQL/MariaDB.
- Mode mock Sprint 2 (`NEXT_PUBLIC_SPRINT2_MOCK=1`) tidak memuat stub UC06; halaman UC06 selalu memanggil Laravel.

## 5. Temuan untuk backend (Demas)

1. `GET /maintenance-cases/{case}/history` hanya memuat **satu** `push_detected` (dari `last_push_sha`); push sebelum revisi tidak tercatat, sehingga rantai revisi BR-18 terlihat tanpa push pertama.
2. Event `backlog_created.detail.status` berisi status backlog **saat ini**, bukan saat dibentuk (UI sengaja tidak menampilkannya).
3. Sinkronisasi `Merged` (FR-UC06-16) belum ada; UI hanya menjelaskan bahwa Selesai menunggu Merged.

## 6. Koordinasi

PR #55 (W14-02, Hanif) juga menambah halaman `/pull-requests` dengan kontrak UC06 berbeda (migration dan request class sendiri) dan saat ini konflik dengan `main`. W14-03 mengikuti kontrak yang sudah di `main` (`contracts/uc06-api.md`). Perlu keputusan tim agar hanya satu frontend UC06 yang di-merge.
