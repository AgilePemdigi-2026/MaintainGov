# Handoff penyelesaian Sprint 1

Pembaruan 26 Oktober 2026. Basis kode main `6383a700d3b0f5e0240dfa8f9323ef73631b555d`; branch tujuan `fix/sprint1-completion`. Paket ini menyiapkan perubahan teknis, pengujian dan evidence. Commit/push dilakukan pengguna; CI baru harus diperiksa setelah push. Pekerjaan auth Demas dan integrasi Hanif pada PR #18/#17 dipertahankan.

## Issue yang sudah ada

Tidak perlu membuat lima issue baru atau menggandakan poin. Lima issue ini merupakan tugas untuk empat anggota tim yang ditemukan pada repository; W11-05 adalah tugas koordinasi tambahan Gerard.

| Issue / task | Assignee GitHub | Yang diperiksa dan dilengkapi sebelum ditutup |
| --- | --- | --- |
| [#12 W11-01](https://github.com/AgilePemdigi-2026/MaintainGov/issues/12) | Gerard / grdviriyandraa | UC01, kontrak/model/rule dan artefak konsisten; evaluasi label manusia, sumber/lisensi dataset dan keputusan domain tercatat |
| [#13 W11-02](https://github.com/AgilePemdigi-2026/MaintainGov/issues/13) | Demas / demasAxel | Login/logout, remember-token replay, RBAC, skema/persistensi MySQL dan peer review |
| [#14 W11-03](https://github.com/AgilePemdigi-2026/MaintainGov/issues/14) | Hanif / Haniippp | Kontrak runtime, pagination, recovery/attempt, kegagalan layanan, BDD dan CI |
| [#15 W11-04](https://github.com/AgilePemdigi-2026/MaintainGov/issues/15) | Valisha / leeteryx | UI login/upload/hasil, validasi CSV, sumber case, sesi, desktop/mobile dan artefak GUI; review versi demo |
| [#16 W11-05](https://github.com/AgilePemdigi-2026/MaintainGov/issues/16) | Gerard / grdviriyandraa | Review Sprint, reviewer berbeda dari penulis, SHA demo, keputusan PO, laporan dan burndown berdasarkan acceptance |

## Yang ikut paket push

- API status ringan dan halaman reviews/cases/source reviews yang dibatasi 100 record per halaman; UI memakai 25 per halaman.
- Pemulihan polling sementara/manual dan pengalihan saat sesi berakhir.
- Validasi CSV 10.000.000 byte/10.000 record, pesan kesalahan dan pemilihan file pengganti.
- Pengaman submit, required field login, job legacy, attempt lama dan threshold recovery yang aman. CLI --timeout/--dry-run dipertahankan; --older-than menjadi alias.
- Kontrak JSON yang diekspor dari Pydantic dan pemeriksaan drift; evaluator label manusia dan benchmark kapasitas.
- Tes regresi, HTTP BDD, lima tes browser, workflow lima job, sumber diagram dan evidence aktual.
- Dokumentasi reproduksi, batas metode, traceability dan handoff. Atribusi anggota dari riwayat repository tetap terjaga.

Tidak ada migrasi baru dalam paket ini. Tidak ada perubahan arsitektur utama, UC02–UC06 atau klaim fitur Sprint berikutnya selesai.

## Tahap pemasangan di Windows / VS Code

1. Buka terminal di root MaintainGov. Jalankan git status; lanjutkan hanya bila bersih. Periksa git branch --show-current. Branch yang dipakai adalah fix/sprint1-completion.
2. Jalankan git fetch origin dan git merge --ff-only origin/main. Jika ada konflik/divergensi, periksa terlebih dahulu; jangan melakukan reset/force push.
3. Download MaintainGov_Sprint1_Final.patch ke Downloads. Jalankan:

```powershell
$patchS1 = Join-Path $env:USERPROFILE 'Downloads\MaintainGov_Sprint1_Final.patch'
git apply --check "$patchS1"
git apply --index "$patchS1"
git diff --cached --stat
git diff --cached --check
```

Jika git apply melaporkan error, berhenti; perintah berikutnya tidak menjadi solusi untuk konflik. Jangan menerapkan patch lama bersamaan dengan paket ini. --index menyiapkan hanya perubahan dari patch untuk commit. Tidak perlu menyalin file satu per satu.

4. Periksa Source Control dan diff. Dependensi/test lokal yang tersedia dapat dijalankan sesuai SETUP_UC01.md; seluruh job CI tetap perlu berhasil setelah push. Jangan memasukkan .env, token, data mentah, model privat, vendor, node_modules atau keluaran browser.
5. Periksa identitas commit dengan git config user.name dan git config user.email. Jika kosong, isi nama kamu dan email GitHub yang diverifikasi atau alamat noreply GitHub milikmu. Jangan menyalin identitas orang lain.
6. Commit dan push:

```powershell
git commit -m "fix(sprint1): complete analysis flow, recovery and validation"
git push -u origin fix/sprint1-completion
```

Login GitHub di browser lokal bila Git meminta. Jika akses tulis organisasi ditolak, minta maintainer memastikan akses Write; alternatifnya fork dan kirim PR dari fork. Jangan membagikan token/password ke chat.

7. Buka perbandingan main...fix/sprint1-completion di GitHub. Buat PR ke main; gunakan deskripsi PR_BODY.md. Tunggu kelima job CI sukses, lakukan review dan demo. Jangan menutup issue hanya karena push berhasil.
8. Setelah reviewer/PO benar-benar menyetujui, catat bukti pada tabel di bawah dan PROGRESS_TRACKER.md, perbarui checklist issue masing-masing, lalu merge melalui proses tim.

## Gate penerimaan yang tetap harus dilakukan manusia

- Dua rater berbeda dan reviewer memberi label/consensus pada sampel manusia; jalankan evaluator hanya setelah label approved. Bukti yang tersedia belum memiliki label manusia approved.
- Owner dataset melengkapi URL sumber, pembuat/lisensi dan menyediakan full CSV asli jika diminta untuk pembuktian kapasitas dataset asli.
- Tim/domain menyetujui rules dan keputusan SRS D03/D04/D06/D07/D08. Case tetap Pending; rekomendasi bukan keputusan otomatis.
- Reviewer berbeda dari penulis memeriksa PR, UI, artefak dan bukti; periksa browser/NFR lain yang belum diuji, termasuk klaim 25 pengguna bersamaan bila menjadi syarat.
- PO yang berwenang menyatakan keputusan terhadap versi demo. Hanya setelah acceptance dicatat, story terkait menjadi Done dan poin dibakar. Baseline Sprint 1 adalah 10, total proyek 34; setelah seluruh 10 diterima, sisa proyek 24.

| Fitur | SHA/versi demo | Hasil dan bukti | Reviewer | PO / tanggal / keputusan |
| --- | --- | --- | --- | --- |
| Login/RBAC — 4 poin | Belum diisi setelah push | Bukti lokal tersedia; review tim terbuka | Belum dicatat | Belum dicatat |
| Logout — 1 poin | Belum diisi setelah push | Bukti lokal tersedia; review tim terbuka | Belum dicatat | Belum dicatat |
| UC01 — 5 poin | Belum diisi setelah push | Teknis teruji; label/domain/provenance dan acceptance terbuka | Belum dicatat | Belum dicatat |
