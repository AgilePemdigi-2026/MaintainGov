---
name: Tugas implementasi MaintainGov
about: Satu tugas dengan pemilik, acuan SRS dan kriteria penerimaan
title: '[W10-02] Menyiapkan environment frontend, backend, layanan analisis, dan database'
labels: ''
assignees: ''
---

## Tujuan

Menyiapkan proyek agar frontend, backend, layanan analisis, dan database bisa dijalankan oleh anggota kelompok.

## Kepemilikan dan waktu

- Pemilik fitur/use case: Login, logout, dan UC01 (didukung melalui setup environment)
- Penanggung jawab tugas (satu orang): Hanif
- Pendukung: Demas untuk Laravel/MySQL, Valisha untuk Next.js, dan Gerard untuk layanan analisis
- Reviewer (berbeda dari penulis): Demas
- Sprint/minggu dan target tanggal: Sprint 1, Week 10; tersedia sebelum implementasi yang membutuhkan environment tersebut dimulai
- Estimasi jam; sumber poin fitur (jangan menggandakan poin): 3 jam, di luar bantuan dan review; poin mengikuti fitur Sprint 1 yang didukung, tidak menambah poin fitur baru

## Keterlacakan

- ID tugas pada rencana: W10-02
- FR/BR/NFR: NFR-MNT-02 (dokumentasi konfigurasi dan dependensi); NFR-PORT-03 (konfigurasi lingkungan)
- Use case / user story / scenario: Login, logout, dan UC01
- Artefak dan kontrak yang terpengaruh: struktur aplikasi, konfigurasi, lockfile, `.env.example`, dan petunjuk menjalankan proyek
- Dependensi atau keputusan yang belum ditutup: akses repository dan kesesuaian versi perangkat lunak pada laptop anggota

## Acceptance criteria

- [ ] Hasil utama yang dapat diamati: Next.js, Laravel, dan FastAPI bisa dijalankan; Laravel berhasil terhubung ke MySQL.
- [ ] Kondisi gagal/alternatif yang harus ditangani: kendala instalasi, perbedaan versi, atau konfigurasi yang ditemukan beserta penyelesaiannya dicatat.
- [ ] Pemeriksaan role/persistensi/integrasi yang relevan:
  - [ ] Versi runtime dan dependensi dicatat.
  - [ ] `.env.example` tersedia tanpa password atau token asli.
  - [ ] Langkah instalasi, konfigurasi, dan menjalankan aplikasi ditulis.
  - [ ] Petunjuk setup berhasil dicoba oleh anggota selain penulis.

## Bukti penyelesaian

- [ ] Implementasi dan artefak konsisten.
- [ ] Pengujian relevan dijalankan; hasil menjalankan setiap komponen dan langkah reproduksi ditautkan.
- [ ] PR direview (commit dan PR ditautkan pada issue); reviewer sudah mencoba petunjuk setup; SHA/versi demo dicatat.
- [ ] Tracker diperbarui; gap/penerimaan fitur tidak diklaim sebelum diverifikasi.

## Blocker dan handoff

Catat jika ada masalah instalasi, perbedaan versi, atau konfigurasi. Setelah setup selesai, lanjutkan ke database, API login, halaman login, dan analisis awal sesuai pembagian tugas.
