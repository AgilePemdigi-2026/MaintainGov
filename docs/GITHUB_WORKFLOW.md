# Alur GitHub MaintainGov

Repository kelompok: [AgilePemdigi-2026/MaintainGov](https://github.com/AgilePemdigi-2026/MaintainGov). Dokumen ini mengatur pembagian pekerjaan melalui issue, commit, pull request, dan review. Setiap anggota menggunakan akun serta identitas Git masing-masing.

## 1. Pola kerja

Gunakan branch terpisah untuk tiap tugas. Perubahan digabungkan ke branch utama setelah diperiksa anggota lain. Untuk kelompok empat orang, alur ini cukup sederhana selama review dan penggabungan dilakukan rutin.

Alur: **issue -> branch -> perubahan kecil -> commit + push -> pull request -> review anggota lain -> pemeriksaan lulus -> merge -> tracker diperbarui**. Pola branch/review ini mengikuti [GitHub flow](https://docs.github.com/en/get-started/using-github/github-flow).

Contoh branch: `feat/w10-05-auth-session`, `feat/w10-07-analysis-baseline`, `docs/w10-14-uc01-artifacts`. ID W10 adalah ID perencanaan lokal; nomor issue GitHub akan berbeda dan harus ditautkan setelah dibuat.

Masing-masing anggota mengirim pekerjaannya melalui akun sendiri agar kontribusinya tercatat. Saat bekerja berpasangan, catat siapa yang mengerjakan bagian tersebut.

## 2. Kapan commit dan push

Commit dibuat setelah ada satu hasil yang bisa dijelaskan, misalnya migration, login, atau pengujian akses. Push setelah perubahan diperiksa, sebelum diserahkan ke anggota lain, atau sebelum mengakhiri sesi. Pada sesi yang panjang, usahakan membagikan hasil setiap 60-120 menit jika ada perubahan yang berarti.

Contoh pemecahan pekerjaan auth yang masuk akal:

1. `feat(auth): add internal accounts and role seeders`
2. `feat(auth): implement session login and logout`
3. `test(auth): verify forbidden role access and revoked sessions`
4. `docs(auth): align session flow and setup instructions`

Contoh analisis: preprocessing -> lexicon conflict rules -> grouping/priority -> pengujian -> dokumentasi hasil evaluasi. Jumlah commit mengikuti kemajuan nyata; tidak harus empat commit untuk setiap task. [GitHub menyarankan perubahan kecil yang bermakna](https://github.com/github/docs/blob/main/content/pull-requests/get-started/pull-request-quickstart.md).

Push mengirim commit lokal ke GitHub. Push berulang tanpa commit baru tidak menambah hitungan. Pecah pekerjaan berdasarkan hasilnya; perbaikan bug, pengujian, API, dan artefak bisa menjadi commit tersendiri jika memang ada perubahan.

Kebiasaan tiap sesi:

- Awal sesi: pilih issue dan sinkronkan branch dasar.
- Saat satu unit selesai: periksa diff, jalankan pemeriksaan relevan, commit dengan pesan jelas.
- Sebelum berpindah tugas/pairing/istirahat panjang: push branch dan catat status singkat.
- Akhir sesi: push kemajuan yang layak disimpan, buka/update PR, tulis blocker dan langkah berikutnya.
- Akhir hari kerja kelompok: Gerard mengecek pekerjaan aktif yang belum dibagikan dan kebutuhan reviewer.

Gerard mengecek pekerjaan yang belum dibagikan dan PR yang masih menunggu review pada akhir hari kerja kelompok.

## 3. Atribusi dan cara hitung

Setiap anggota memakai GitHub account dan email commit yang terhubung ke akun sendiri. Gunakan email `noreply` yang diberikan GitHub bila ingin menyembunyikan email pribadi; verifikasi pada pengaturan akun. [GitHub menjelaskan kaitan email lokal dan atribusi commit](https://docs.github.com/en/account-and-profile/how-tos/email-preferences/setting-your-commit-email-address).

Bedakan tiga ukuran: jumlah commit dalam riwayat repository, kontribusi pada grafik profil, dan ukuran yang dipakai pengampu. Grafik profil memiliki syarat, termasuk email terhubung dan commit mencapai default branch/gh-pages; lihat [kriteria kontribusi GitHub](https://docs.github.com/en/account-and-profile/reference/profile-contributions-reference). Kontribusi branch yang belum digabung dapat belum tampak di grafik profil. Jangan menganggap grafik profil sebagai bukti lengkap pekerjaan satu repository.

Untuk mempertahankan riwayat perubahan tiap anggota, metode merge yang diusulkan adalah **Create a merge commit**. Squash menggabungkan commit PR menjadi satu pada base branch. Sesuaikan kebijakan ini setelah cara penilaian pengampu jelas. [Perbedaan metode merge](https://docs.github.com/en/pull-requests/reference/pull-request-merges). Saat rekap, pisahkan merge commit dari commit implementasi.

Untuk pairing, simpan bukti peserta dan pembagian pekerjaan; co-author hanya bila memang mengerjakan bersama dan emailnya benar. Jangan mengubah tanggal/author atau membuat kontribusi rekaan. Anggota yang mengubah artefak harus memahami dan dapat menjelaskan perubahannya.

Gerard perlu memastikan periode penilaian, branch yang dihitung, perlakuan merge/squash/co-author, serta minimum commit jika ada. Panduan monitoring belum mencantumkan batas tersebut.

## 4. Review dan perlindungan main

Sebelum merge, PR perlu ditinjau minimal satu anggota lain, pemeriksaan relevan lulus, konflik selesai, dan langkah demo tersedia. Maintainer mengatur perlindungan branch/ruleset yang mendukung alur tersebut jika tersedia pada repository.

Untuk perubahan dokumentasi, periksa isi, tautan, angka, dan konsistensinya. Setelah aplikasi tersedia, tambahkan pemeriksaan otomatis yang sesuai: format/lint/typecheck, build web, pengujian aturan API, serta pengujian analisis dan integrasi. Perintah pada workflow harus sesuai dengan aplikasi yang sudah tersedia.

Pemilik task meminta reviewer; Gerard hanya membantu bila antrean melewati satu hari kerja kelompok. Penulis tidak menyetujui PR sendiri. Gunakan PR kecil yang dapat ditinjau dalam satu sesi; [GitHub menjelaskan manfaat PR yang terfokus](https://docs.github.com/en/pull-requests/concepts/helping-others-review-your-changes).

## 5. Catatan kontribusi

| Bukti | Dicatat oleh | Makna |
| --- | --- | --- |
| Issue dengan owner, FR, acceptance criteria | Pemilik fitur/task | Apa yang menjadi tanggung jawab individu |
| Commit dengan diff dan identitas benar | Pembuat perubahan | Pekerjaan yang benar-benar dilakukan |
| PR dan review | Penulis + reviewer | Integrasi dan pemeriksaan oleh anggota lain |
| Artefak PlantUML dan revisinya | Pemilik fitur; pertahankan kredit penulis lama | Konsistensi rancangan dan implementasi |
| BDD/hasil uji, demo dan penerimaan PO | Pemilik fitur + PO | Perilaku software telah diverifikasi |
| Tracker dan risiko | Semua anggota; Gerard merekap | Konsistensi progres dan tindak lanjut |

Contoh perintah untuk melihat riwayat commit:

```powershell
git log origin/main --no-merges --since="YYYY-MM-DD" --until="YYYY-MM-DD" --format="%h | %aI | %an | %ae | %s"
git shortlog -sne --no-merges origin/main
```

Perintah pertama dapat dibatasi tanggal sesuai periode penilaian; perintah kedua merangkum seluruh riwayat yang terjangkau dari origin/main. Angka ini menghitung commit, bukan kualitas/tingkat kesulitan; cocokkan dengan issue, PR dan demo. Sesuaikan ref jika default branch berbeda.

## 6. Berkas yang dibagikan

Commit source code, lockfile, kontrak, migration, fixture tersamar, artefak sumber dan dokumentasi. `.gitignore` mengecualikan rahasia, dependensi, hasil build dan bahan sementara. Raw dataset tidak otomatis masuk Git; lengkapi provenance dan kebutuhan pembagian dahulu. Simpan log pengujian mentah pada lokasi yang sesuai; repository cukup menyimpan ringkasan yang dapat ditelusuri ke hasil run, bukan rahasia atau data reviewer mentah.
