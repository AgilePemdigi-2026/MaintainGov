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