# Laporan Audit W15-01 — Full BDD, Integration Testing & Sprint 3 Acceptance

| Field | Nilai |
| --- | --- |
| Issue | [#51 — [W15-01] Full BDD, Integration Testing & Sprint 3 Acceptance](https://github.com/AgilePemdigi-2026/MaintainGov/issues/51) |
| PIC / Reviewer | Gerard Viriyandra (`@grdviriyandraa`) / Demas Axel Marfiansyah (`@demasAxel`) |
| Tanggal audit | 20 November 2026 |
| Branch audit | `feature/W15-01-backend-testing` |
| HEAD / target CI | `967f29db79717c71c2cdcef80985758b3a180729` (sama dengan `main` dan `origin/main`) |
| CI yang diverifikasi | [Run 44556777490](https://github.com/AgilePemdigi-2026/MaintainGov/actions/runs/44556777490) — **success**, 5/5 job |
| Lingkungan | Windows; PHPUnit SQLite in-memory; Playwright Chromium; MySQL lokal tidak aktif; GitHub read-only |
| Sifat audit | Read-only. Tidak ada branch/PR/review/merge yang dibuat di GitHub. |
| Status akhir | **BELUM MEMENUHI W15-01 / NOT ACCEPTED** |

> **Aturan bukti yang dipakai.** Mock, `Http::fake`, contract test, dan server GitHub tiruan **tidak** dihitung sebagai PASS untuk acceptance yang mensyaratkan integrasi live. Status **BLOCKED** berarti tidak dapat dijalankan karena dependency/izin/identitas, bukan lulus. Status **PARTIAL** berarti satu lapisan sudah teruji tetapi lapisan yang diwajibkan belum.

---

## 0. Ringkasan Eksekutif

W15-01 **belum dapat dinyatakan selesai**. Baseline otomatis proyek sehat, tetapi acceptance Sprint 3 belum terbukti pada jalur live yang menjadi inti issue ini.

**Yang sudah hijau:**

- PHPUnit MySQL 8 di CI: **278 passed, 1.478 assertions**.
- PHP Behat di CI: **9/9 skenario lulus** (41 langkah).
- Playwright di CI: **48/48 test lulus**, 4 test live di-skip.
- Python/FastAPI di CI dengan model sintetis: **20 passed**.
- Runtime contract/human evaluator: **13 passed**.
- Next.js typecheck dan production build: **lulus**.
- CI 5/5 job pada SHA `967f29d` lulus.
- `github:check` dan `github:smoke-uc05 --require-write` lulus sebagai **read-only**; token melaporkan `push_permission=granted`.

**Yang belum terbukti / memblokir:**

1. **Tidak ada satu pun Pull Request nyata yang dibuat melalui MaintainGov.** Semua test UC05 memakai `Http::fake`; bukti W14-03 memakai server GitHub tiruan (`#9001`/`#9002` tidak ada di GitHub).
2. **UC06 live dengan reviewer berbeda dari author tidak mungkin dilakukan dengan satu PAT.** Satu bearer token membuat PR dan review atas nama pemilik token yang sama; GitHub menolak self-review. Arsitektur belum punya token per pengguna/GitHub App.
3. **Webhook live belum aktif.** `GITHUB_WEBHOOK_SECRET` kosong di `apps/api/.env`; tidak ada tunnel/URL publik; merge sync baru diuji dengan webhook palsu.
4. **PHP Behat belum mencakup UC04–UC06.** Hanya ada 9 skenario: auth (6), UC01 (2), dan satu alur UC02/UC03/claim.
5. **Playwright live UC02–UC06 tidak dijalankan.** Empat test live di-skip by default; skrip penyiapan `tools/sprint2_real_e2e.py` yang dirujuk spec bahkan tidak ada di repo.
6. **Python dengan artefak model nyata gagal lokal:** 17 passed, 3 failed karena `sentiment_pipeline.joblib` tidak ada. CI hanya memakai model sintetis.
7. **Belum ada commit/PR/artefak W15-01.** Branch audit tidak memiliki commit di atas `main`; tracker, burndown, defect log resmi, regression checklist, dan internal acceptance belum diisi.

**Skor acceptance:** dari **30 checkbox** di issue #51 — **7 PASS, 10 PARTIAL, 4 BLOCKED, 8 FAIL, 1 NOT PROVEN**. Artinya hanya **7/30 (23%)** yang terpenuhi penuh, dan sebagian besar sisanya menyentuh justru bagian live integration yang menjadi tujuan W15-01.

| Status | Jumlah | Arti singkat |
| --- | ---: | --- |
| PASS | 7 | Dijalankan dan lulus, atau CI hijau pada SHA target |
| PARTIAL | 10 | Satu lapisan lulus, lapisan live/artefak wajib belum |
| BLOCKED | 4 | Tidak dapat dijalankan karena dependency eksternal |
| FAIL | 8 | Artefak/skenario wajib tidak ada atau gagal |
| NOT PROVEN | 1 | Tidak ada bukti untuk menyatakan lulus |
| **Total** | **30** | |

---

## 1. Ruang Lingkup, Metode, dan Batas Audit

### 1.1 Ruang lingkup

- Login/logout dan role `Tim Maintenance` / `Developer`.
- UC01 analisis feedback sampai `Maintenance Case` berstatus `Pending`.
- UC02 validasi case (`Ya`/`Tidak`) dan pembentukan backlog.
- UC03 edit backlog dan persistensi.
- UC04 claim issue, assignment, branch, dan verifikasi push GitHub.
- UC05 pembuatan Pull Request dan penyimpanan metadata.
- UC06 review Pull Request (Approve/Request Changes), status, dan riwayat.
- Sinkronisasi status PR setelah merge di GitHub.
- Lapisan uji: PHPUnit, PHP Behat, Playwright, Python/FastAPI, MySQL 8, Next.js typecheck/build, GitHub Actions CI.

### 1.2 Metode

1. Inspeksi kode route, controller, service, migration, model, seeder, request validation, dan frontend.
2. Menjalankan test yang aman secara lokal: PHPUnit, typecheck, build, pytest, dan 43 test Playwright mocked.
3. Mengaudit artefak CI run `44556777490` dan mengunduh artefak `sprint1-integration-evidence`.
4. Menjalankan pemeriksaan GitHub **read-only**: `github:check`, `github:smoke-uc05 --require-write`, dan `GET /user` untuk memastikan pemilik token (tanpa mencetak token).
5. Membandingkan issue #51, SRS/contract, kode, test, dan dokumentasi W14.

### 1.3 Batas audit

- Tidak ada operasi tulis ke GitHub: tidak ada branch, PR, review, merge, atau webhook delivery.
- MySQL lokal tidak aktif saat audit (`SQLSTATE[HY000] [2002] Connection refused`), sehingga hasil MySQL lokal tidak dapat direproduksi; bukti MySQL berasal dari CI.
- PHP Behat lokal tidak dijalankan karena stack API/MySQL tidak aktif; hasil Behat berasal dari artefak CI.
- Empat test Playwright live tidak dijalankan karena environment dan data uji belum tersedia.
- Artefak model nyata (`sentiment_pipeline.joblib`) tidak tersedia; pytest lokal gagal pada tiga test yang membutuhkannya.
- Tidak ada perubahan kode sumber dalam audit ini. Satu-satunya perubahan repository adalah dokumen laporan ini.

---

## 2. Baseline Repository dan CI

### 2.1 Kondisi branch

```text
Branch : feature/W15-01-backend-testing
HEAD   : 967f29db79717c71c2cdcef80985758b3a180729
main   : 967f29db79717c71c2cdcef80985758b3a180729
Status : tidak ada commit di atas main
Untracked: docs/artifacts/domain_rules.json
           docs/artifacts/slang_map.json
```

Implikasi:

- Belum ada **target commit SHA W15-01**. SHA yang ada adalah baseline `main`.
- Belum ada PR perbaikan test suite yang dapat ditautkan ke acceptance.
- Dua file JSON untracked adalah pekerjaan lain/artefak UC01; jangan tercampur ke commit W15 tanpa keputusan.

### 2.2 CI baseline

| Job | Hasil | Bukti |
| --- | --- | --- |
| Laravel / MySQL 8 | **success** | 278 passed, 1.478 assertions, 10,33 s |
| HTTP BDD, Chromium and synthetic capacity | **success** | 48 Playwright passed, 4 skipped; Behat 9/9; Python BDD 8 passed + 1 skipped, lalu service-down 1 passed |
| Frontend typecheck and production build | **success** | `npm run typecheck` + `npm run build` |
| FastAPI with synthetic CI model | **success** | 20 passed (model sintetis) |
| Runtime contracts and human-label evaluator | **success** | 13 passed |

CI ini membuktikan **baseline Sprint 1–3 yang sudah ada** sehat. CI ini **bukan** bukti W15-01 karena tidak mengandung perubahan W15 dan tidak menjalankan live GitHub write, live UC04–UC06 Behat, atau Playwright live UC02–UC06.

---

## 3. Matriks Acceptance Criteria W15-01

### A. Functional & Integration Testing

| ID | Kriteria (issue #51) | Status | Bukti / catatan |
| --- | --- | --- | --- |
| A1 | Login dan Logout sesuai role Tim Maintenance dan Developer | **PASS** | `AuthTest` 13 test; route role gate; Playwright Sprint 1 5/5 lulus di CI dengan Laravel/MySQL nyata |
| A2 | UC01 memproses CSV via Laravel, FastAPI, MySQL sampai Case `Pending` | **PASS** | `AnalysisFlowTest` 17 test; Behat UC01 2/2; Python BDD; CI MySQL 8 |
| A3 | UC02 memvalidasi case (Ya/Tidak), backlog hanya untuk case valid | **PASS** | `MaintenanceCaseValidationTest` 15 test; Behat Sprint 2; Playwright 9/9 |
| A4 | UC03 mengedit backlog dan menyimpan perubahan | **PASS** | `MaintenanceBacklogTest` 15 test; Behat Sprint 2 mengedit backlog; Playwright 10/10 |
| A5 | UC04 claim issue, assignment Developer, verifikasi push GitHub | **PARTIAL** | 23 claim test + 11 push test + 24 webhook test lulus, tetapi verifikasi push **live** tidak dijalankan; webhook secret kosong |
| A6 | UC05 membuat PR nyata dari MaintainGov dan menyimpan metadata | **BLOCKED** | 30 test pembuatan PR semuanya `Http::fake`; tidak ada URL PR nyata; smoke test hanya read-only; W14 mencatat write 403 |
| A7 | UC06 Approve/Request Changes oleh reviewer berbeda dari author | **BLOCKED** | 16 test review lulus dengan fake GitHub; live review tidak mungkin dengan satu PAT dan identitas reviewer yang ada |
| A8 | Status dan riwayat dari feedback awal sampai PR dan review | **PARTIAL** | `historyForCase` + test + 7 Playwright lulus; hanya satu event push, status backlog memakai status saat ini; belum ada data live |
| A9 | Status PR setelah merge di GitHub tersinkron ke MaintainGov | **PARTIAL** | Kode merge sync + 2 webhook test + test `/sync` ada; tidak ada webhook live, tunnel, atau merge nyata |

### B. BDD & Automated Testing

| ID | Kriteria (issue #51) | Status | Bukti / catatan |
| --- | --- | --- | --- |
| B1 | Menjalankan seluruh skenario PHP Behat UC01–UC06 | **FAIL** | Hanya 9 skenario: auth 6, UC01 2, UC02/UC03/claim 1. Tidak ada Behat UC04 push, UC05 PR, UC06 review, status/riwayat, merge |
| B2 | Skenario normal dan alternatif sesuai User Story/UC Spec | **PARTIAL** | PHPUnit mencakup banyak alternatif; Behat tidak. Belum ada BDD alternatif untuk UC04–UC06 |
| B3 | Role restriction, invalid input, duplicate, concurrency, recovery | **PARTIAL** | PHPUnit kuat (role, 409, idempotensi, lock, recovery); tidak ada BDD/live untuk concurrency UC05/UC06 |
| B4 | Laravel PHPUnit dan pengujian database MySQL | **PASS** | CI MySQL 8: 278 passed, 1.478 assertions. Lokal SQLite: 278 test, 277 pass, 1 skip |
| B5 | Python/FastAPI tests layanan analisis | **PARTIAL** | CI 20 passed dengan model sintetis; lokal 17 pass, 3 fail karena artefak model nyata tidak ada |
| B6 | Playwright E2E di browser dengan backend nyata | **PARTIAL** | CI 48/48 lulus, tetapi 4 live test di-skip; UC02–UC06 memakai mock; 8 test menargetkan halaman legacy |
| B7 | Next.js typecheck dan production build | **PASS** | `npm run typecheck` dan `npm run build` lulus lokal dan CI |
| B8 | GitHub Actions CI lulus pada target commit SHA | **PASS (baseline)** | 5/5 job lulus pada `967f29d`; belum ada SHA W15 karena belum ada commit W15 |

### C. End-to-End & Acceptance Testing

| ID | Kriteria (issue #51) | Status | Bukti / catatan |
| --- | --- | --- | --- |
| C1 | Alur lengkap Login Maintenance → CSV → Case → Validasi → Backlog → Edit → Login Developer → Claim → Push → PR → Review | **BLOCKED** | Tidak ada satu test yang menjalankan alur ini end-to-end dengan GitHub nyata. Bagian-bagian tersebar di mock/fake |
| C2 | Approve, Request Changes, perbaikan/re-review | **BLOCKED** | Hanya mock/fake. W14-03 memakai server GitHub tiruan, bukan GitHub |
| C3 | Tidak ada critical bug pada alur utama | **NOT PROVEN** | Tidak ada bukti critical bug dari test otomatis, tetapi alur live belum dijalankan sehingga tidak adanya critical bug belum dapat dibuktikan |
| C4 | Test result report, defect log, regression checklist | **FAIL** | Belum ada artefak resmi W15. Laporan ini adalah input audit, bukan laporan penerimaan resmi |
| C5 | Internal acceptance bersama anggota | **FAIL** | Tidak ada catatan rapat/checklist acceptance; issue #51 masih open |
| C6 | Memperbarui status fitur berdasarkan penerimaan aktual | **FAIL** | `PROGRESS_TRACKER.md` masih menyatakan UC04–UC06 “Belum diverifikasi”; README masih narasi Sprint 1 |

### Bukti Penyelesaian

| ID | Bukti (issue #51) | Status | Bukti / catatan |
| --- | --- | --- | --- |
| E1 | PR dan commit SHA untuk perbaikan test suite | **FAIL** | Branch tidak punya commit di atas `main`; belum ada PR W15 |
| E2 | Hasil PHP Behat, PHPUnit, Playwright, Python Tests, CI | **PARTIAL** | PHPUnit/Playwright/Python/CI ada; Behat hanya 9 skenario dan bukan UC01–UC06; live tidak ada |
| E3 | Screenshot/rekaman E2E aplikasi | **PARTIAL** | Screenshot W14-03 ada, tetapi memakai server GitHub tiruan; tidak ada rekaman W15 live |
| E4 | URL Pull Request yang benar-benar dibuat melalui MaintainGov | **FAIL** | Tidak ada. `#9001`/`#9002` di W14-03 adalah server tiruan dan tidak ada di GitHub |
| E5 | Test matrix dan defect report | **PARTIAL** | Matriks dan defect log draft ada di laporan ini; artefak resmi W15 belum ada |
| E6 | Sprint Review dan acceptance checklist | **FAIL** | Belum ada |
| E7 | Burndown chart final berdasarkan Definition of Done | **FAIL** | `PROGRESS_TRACKER.md` bagian burndown/acceptance masih kosong |

---

## 4. Hasil Eksekusi Test per Lapisan

### 4.1 PHPUnit / Laravel

**Lokal (SQLite in-memory):**

```powershell
cd apps/api
php artisan test --compact
```

| Metrik | Hasil |
| --- | --- |
| Total test | **278** |
| Passed | **277** |
| Skipped | **1** |
| Assertions | **1.477** |
| Durasi | **13,3 s** |
| Skip | `SchemaSprint1Test` — CHECK constraint `ulasan.rating` hanya diuji di MySQL |

**CI (MySQL 8):**

| Metrik | Hasil |
| --- | --- |
| Total test | **278** |
| Passed | **278** |
| Skipped | **0** |
| Assertions | **1.478** |
| Durasi | **10,33 s** |

**Catatan:** hasil MySQL lokal tidak dapat direproduksi karena service MySQL tidak aktif. CI MySQL adalah bukti database yang valid, tetapi belum ada log MySQL lokal W15.

**Kekuatan cakupan backend:**

| Area | File | Jumlah test |
| --- | --- | ---: |
| Auth/RBAC | `AuthTest.php` | 13 |
| UC01 analisis | `AnalysisFlowTest.php`, `AnalysisRecoveryTest.php`, `AnalysisPaginationTest.php` | 26 |
| UC02 validasi case | `MaintenanceCaseValidationTest.php` | 15 |
| UC03 backlog | `MaintenanceBacklogTest.php` | 15 |
| UC04 claim | `MaintenanceIssueClaimTest.php` | 23 |
| UC04 push | `PushVerificationTest.php` | 11 |
| UC05 PR | `PullRequestCreationTest.php` | 30 |
| UC06 review | `PullRequestReviewTest.php` | 16 |
| Webhook GitHub | `GitHubWebhookTest.php` | 24 |
| GitHub service/contract | `GitHubServiceTest.php`, `GitHubConnectionTest.php`, `GitHubContractFixtureTest.php`, `GitHubMockContractTest.php` | 49 |
| Alur UC04–UC05 | `Uc04Uc05FlowTest.php` | 1 |

Angka di atas menunjukkan **kedalaman pengujian unit/feature baik**. Tabel ini adalah inventaris area utama, bukan penjumlahan seluruh suite; total aktual tetap 278 test. Masalahnya bukan jumlah test, melainkan **jenis bukti**: mayoritas UC04–UC06 memakai `Http::fake`, bukan GitHub nyata.

### 4.2 PHP Behat

**CI:** `php -d memory_limit=512M vendor/behat/behat/bin/behat --format=pretty`

| Feature | Skenario | Hasil |
| --- | ---: | --- |
| `auth_sprint1.feature` | 6 | 6/6 lulus |
| `uc01_sprint1.feature` | 2 | 2/2 lulus |
| `sprint2_live.feature` | 1 | 1/1 lulus (UC02 + UC03 + claim) |
| **Total** | **9** | **9/9 lulus, 41 langkah** |

**Cakupan yang hilang:**

- UC04: claim sudah ada di Sprint 2, tetapi verifikasi push GitHub, branch creation, stale/superseded push, dan role restriction belum ada sebagai Behat.
- UC05: pembuatan PR, idempotensi, 403/503, rekonsiliasi, validasi branch/SHA belum ada.
- UC06: Approve, Request Changes, self-review, PR non-Open, duplicate review, sync gagal/retry, role restriction belum ada.
- Status/Riwayat: timeline, urutan event, akses role, multiple push/PR belum ada.
- Merge sync: merge webhook → `Selesai`, closed tanpa merge tidak `Selesai` belum ada.

**Kesimpulan:** 9/9 **yang ada** lulus, tetapi acceptance B1 mensyaratkan **seluruh UC01–UC06**. Ini FAIL cakupan, bukan FAIL eksekusi.

### 4.3 Playwright

**Inventaris:**

| File | Jumlah | Jenis | Halaman |
| --- | ---: | --- | --- |
| `sprint1.spec.ts` | 5 | Real Laravel/MySQL | Login, analisis, status, mobile |
| `sprint2-backlog.spec.ts` | 10 | Mock API | `/maintenance-backlog` |
| `sprint2-case.spec.ts` | 9 | Mock API | `/maintenance-case` |
| `sprint2-issue-pr.spec.ts` | 9 | Mock API | `/maintenance-issue` + form PR |
| `sprint2-pull-request-review.spec.ts` | 8 | Mock API | **`/pull-requests` (legacy)** |
| `sprint3-review.spec.ts` | 7 | Mock API | **`/pull-request` (aktif)** + `/riwayat` |
| `sprint2-live.spec.ts` | 2 | Opt-in, skip default | UC02–UC05 read-only |
| `sprint3-live.spec.ts` | 2 | Opt-in, skip default | UC06 + Status/Riwayat read-only |
| **Total** | **52** | | 48 run + 4 skip |

**Lokal (yang dapat dijalankan tanpa backend/MySQL):**

```powershell
cd apps/web
npx playwright test sprint2-backlog.spec.ts sprint2-case.spec.ts sprint2-issue-pr.spec.ts sprint2-pull-request-review.spec.ts sprint3-review.spec.ts --reporter=line
```

Hasil: **43/43 passed** dalam 42,8 detik. Ini **bukan** bukti live; semua API di-mock.

**CI:**

- `npm run test:e2e` → **48 passed, 4 skipped, 0 failed**.
- 4 yang skipped adalah `sprint2-live` (2) dan `sprint3-live` (2), karena env opt-in tidak diset.

**Temuan penting:**

1. **8 dari 48 test CI menargetkan halaman legacy `/pull-requests`** dan mengirim payload lama:
   - `sprint2-pull-request-review.spec.ts:103` → `/pull-requests`
   - baris 131 → `decision: "APPROVE"`
   - baris 176 → `decision: "REQUEST_CHANGES", notes`
   Halaman aktif adalah `/pull-request` dengan kontrak `approve`/`request_changes` + `comment`. Jadi 8 test itu **tidak menguji kontrak backend yang sekarang**.
2. Test legacy bahkan menguji **“Tim Maintenance approves”**, perilaku yang sekarang ditolak backend (`role:Developer`) dan bertentangan dengan SRS/contract.
3. Test live tidak reprodusibel dari repo: `sprint2-live.spec.ts` merujuk `tools/sprint2_real_e2e.py`, tetapi folder `tools/` tidak ada.
4. `sprint3-live.spec.ts` hanya **membaca** PR yang sudah disiapkan; tidak membuat PR, tidak mengirim review, tidak merge. Ini bukan E2E alur penuh.

### 4.4 Python / FastAPI

**Lokal:**

```powershell
cd services/analysis
python -m pytest -q
```

| Metrik | Hasil |
| --- | --- |
| Passed | **17** |
| Failed | **3** |
| Durasi | 7,37 s |
| Penyebab | `FileNotFoundError: services/analysis/artifacts/sentiment_pipeline.joblib` |

Test yang gagal:

- `tests/test_analyze.py::test_health_check`
- `tests/test_analyze.py::test_analyze_valid_request`
- `tests/test_analyze.py::test_sentiment_is_from_text_not_input_rating`

**CI:**

- `python -m pytest -q services/analysis/tests` → **20 passed** setelah `generate_ci_artifacts.py`.
- `tests/test_contracts.py tests/test_human_evaluation.py` → **13 passed**.
- Python Behave `tests/bdd/api_sprint1.feature` → **8 passed + 1 skipped**, lalu skenario `@service_down` **1 passed**.

**Cakupan yang tidak masuk CI:**

- `tests/bdd/analyze_feedback.feature` (9 skenario) tidak dijalankan di CI.
- `tests/bdd/authentication.feature` (4 skenario) tidak dijalankan di CI.
- Total 13 skenario Behave Python tidak menjadi bagian pipeline.

**Kesimpulan:** CI lulus dengan model sintetis, tetapi jalur model nyata tidak terbukti. Model sintetis tidak boleh diklaim sebagai kualitas model atau bukti inference nyata.

### 4.5 Next.js

```powershell
cd apps/web
npm run typecheck
npm run build
```

| Pemeriksaan | Hasil |
| --- | --- |
| Typecheck | **PASS** |
| Production build | **PASS** (17 route, termasuk `/pull-request` dan `/pull-requests`) |

Build justru mengonfirmasi duplikasi route: **`/pull-request`** (aktif) dan **`/pull-requests`** (legacy) sama-sama dibangun.

### 4.6 Pemeriksaan GitHub live (read-only)

| Pemeriksaan | Hasil | Catatan |
| --- | --- | --- |
| `php artisan github:check` | **PASS** | Repo `AgilePemdigi-2026/MaintainGov` terbaca; permissions `admin/maintain/push/triage/pull = true` |
| `php artisan github:smoke-uc05 --require-write` | **PASS (read-only)** | `push_permission=granted`; perintah sendiri menyatakan **write path tidak dijalankan** |
| Pemilik token | `grdviriyandraa` (User) | Dicek via `GET /user`, token tidak dicetak |
| `GITHUB_WEBHOOK_SECRET` | **kosong/tidak ada** | Webhook live akan 503/ tidak dapat diverifikasi |
| Live PR/review/merge | **tidak dijalankan** | Tidak ada mutasi GitHub |

**Kontradiksi yang harus diselesaikan:** `github:check` melaporkan `push: true`, tetapi dokumentasi W14 melaporkan HTTP 403 `Resource not accessible by personal access token` saat menulis branch/PR karena fine-grained PAT dianggap kurang `Contents: Read and write` / `Pull requests: Read and write`. `push: true` di metadata repo **bukan** bukti izin tulis fine-grained. Satu-satunya cara menyelesaikan kontradiksi ini adalah uji tulis nyata di repo disposable.

### 4.7 GitHub Actions CI

| Aspek | Hasil |
| --- | --- |
| Status | **success** |
| SHA | `967f29d` |
| Job | 5/5 success |
| PHPUnit MySQL | 278 passed |
| Behat | 9/9 |
| Playwright | 48 passed, 4 skipped |
| Python FastAPI | 20 passed (sintetis) |
| Contracts | 13 passed |
| Frontend | typecheck + build success |

**Keterbatasan CI yang penting:**

- Tidak ada job yang menjalankan **live GitHub write** (PR/review/merge).
- Tidak ada job yang menjalankan Behat UC04–UC06.
- Tidak ada job yang menjalankan Playwright live UC02–UC06.
- Tidak ada job yang memakai model analisis nyata; model dibuat sintetis.
- Workflow bernama “Sprint 1 CI” dan menyimpan artefak 14 hari; bukan arsip bukti rilis jangka panjang.

---

## 5. Analisis per Fitur

### 5.1 Login, Logout, dan Role Access — PASS

- Route memakai `auth` + `role:...`; `AuthTest` mencakup login valid/invalid, `me`, logout, sesi lama, CSRF, rotasi remember token, dan role denial.
- Playwright Sprint 1 di CI berjalan dengan Laravel/MySQL nyata dan lulus 5/5.
- Tidak ada defect fungsional yang ditemukan pada lapisan ini.

**Sisa risiko:** belum ada uji live browser W15 yang baru; bukti berasal dari CI baseline.

### 5.2 UC01 — PASS

- Laravel menerima CSV, queue worker memanggil FastAPI, hasil disimpan, dan case `Pending` terbentuk.
- `AnalysisFlowTest` (17), `AnalysisRecoveryTest` (5), `AnalysisPaginationTest` (4), Behat UC01 (2), Python BDD (9), dan CI MySQL 8 semuanya lulus.
- Idempotensi upload, CSV invalid, service down, retry, dan stale worker sudah diuji.

**Sisa risiko:** model nyata tidak tersedia lokal; CI memakai model sintetis. Ini bukan kegagalan UC01, tetapi membatasi klaim kualitas analisis.

### 5.3 UC02 dan UC03 — PASS

- UC02: `MaintenanceCaseValidationTest` 15 test; Behat Sprint 2 memvalidasi case dan membentuk backlog; Playwright 9/9 (mock).
- UC03: `MaintenanceBacklogTest` 15 test; Behat Sprint 2 mengedit backlog; Playwright 10/10 (mock).
- Role `Tim Maintenance` dan `Developer` dijaga di route/UI.

**Sisa risiko:** 19 test Playwright UC02/UC03 adalah mock, bukan live. Namun BDD Sprint 2 sudah menjalankan validasi/edit/claim pada Laravel/MySQL nyata, sehingga AC fungsional terpenuhi.

### 5.4 UC04 — PARTIAL (live GitHub BLOCKED)

**Yang sudah ada:**

- Claim atomik, assignment, branch preparation, fallback, dan role restriction.
- Verifikasi push manual dan via webhook.
- 24 test webhook: signature invalid, missing secret, stale delivery, duplicate delivery, actor mismatch, superseded push, retry, merged/closed PR.
- `MaintenanceIssueClaimTest` 23, `PushVerificationTest` 11.

**Yang belum:**

- Verifikasi push terhadap **GitHub nyata** dari branch yang benar-benar di-push.
- Webhook live dengan secret dan tunnel.
- Bukti bahwa branch yang dibuat MaintainGov dapat di-push oleh akun Developer yang benar.

**Dampak:** A5 belum bisa PASS. Claim/assignment lokal lulus, tetapi integrasi GitHub-nya belum.

### 5.5 UC05 — BLOCKED (release blocker)

**Yang sudah ada:**

- `PullRequestCreationTest` 30 test: validasi branch/SHA, idempotensi, replay, 403/503, rekonsiliasi, metadata.
- `Uc04Uc05FlowTest` menjalankan claim → push verify → create PR → replay, tetapi seluruh GitHub di-fake.
- `github:smoke-uc05 --require-write` hanya membaca dan tidak membuat PR.

**Yang belum:**

- Tidak ada URL PR nyata yang dibuat melalui MaintainGov.
- Tidak ada bukti `POST /repos/{owner}/{repo}/pulls` berhasil dengan token saat ini.
- Dokumentasi W14 saling bertentangan: `push: true` vs HTTP 403 write.
- Test bernama `test_valid_submission_creates_a_real_github_pull_request...` tetap memakai `Http::fake`; nama test menyesatkan.

**Dampak:** A6 BLOCKED. Ini blocker utama Sprint 3 karena UC06 tidak bisa diuji live tanpa PR nyata.

### 5.6 UC06 — BLOCKED (release blocker arsitektural)

**Yang sudah ada:**

- `PullRequestReviewTest` 16 test: approve, request changes, self-review, PR non-Open, validasi, 404, sync gagal, upsert review, list/filter, detail, history, scoping, sync.
- Route review hanya `role:Developer`; Tim Maintenance ditolak.
- Self-review lokal dicegah dengan membandingkan `developer_id` PR dan `reviewer->user_id` (`PullRequestReviewService.php:267`).
- Status `Ready for Merge` hanya setelah approve tersinkron; `Selesai` hanya setelah merged.

**Blocker utama — satu token, dua identitas:**

- `GitHubService.php:18-19` menyatakan eksplisit: **“GitHub App JWT exchange is not implemented; the configured bearer token is used for both read and write.”**
- PR dibuat dengan token server → GitHub mencatat **pemilik token** sebagai author PR.
- Review dikirim dengan token yang sama → GitHub mencatat **pemilik token** sebagai reviewer.
- GitHub menolak approve PR sendiri. Jadi skenario “Developer A membuat PR, Developer B mereview” **tidak mungkin** dengan satu PAT, walaupun database lokal punya dua user berbeda.
- Token saat ini milik `grdviriyandraa` (Gerard, Tim Maintenance). Seed hanya punya satu Developer (`developer@maintaingov.test`) dan **tidak ada `github_login`** untuk kedua akun.
- Tidak ada penyimpanan token per user, tidak ada GitHub App user-to-server token, dan tidak ada mekanisme switch token per request.

**Blocker sekunder — identity guard tidak ada:**

- `docs/GITHUB_AUTH_PERMISSION_GUIDE.md:51-58` menampilkan snippet `GITHUB_REVIEWER_IDENTITY_MISMATCH`, tetapi kode tidak mengimplementasikannya.
- Test frontend `sprint2-pull-request-review.spec.ts:210` mem-mock 403 identity mismatch yang **tidak pernah diproduksi backend**.
- Menambahkan guard saja tidak menyelesaikan masalah: dengan satu token, guard akan memblokir semua reviewer yang bukan pemilik token. Solusinya adalah token per pengguna/GitHub App, bukan hanya guard.

**Gap fungsional:**

- FR-UC06-05 (menampilkan hasil test/check GitHub) belum diimplementasikan; UI hanya menampilkan changed files/diff dari GitHub publik.
- Riwayat hanya punya satu event `push_detected` dari `last_push_sha`; push revisi hilang.
- `backlog_created.detail.status` memakai status backlog **saat ini**, bukan status saat event dibuat.

**Dampak:** A7 BLOCKED. Ini bukan sekadar kekurangan test; ini **batas arsitektur** yang harus diselesaikan sebelum W15-02/staging.

### 5.7 Status dan Riwayat Maintenance — PARTIAL

**Yang sudah ada:**

- `GET /maintenance-cases/{case}/history` untuk Tim Maintenance dan Developer.
- Timeline case → backlog → issue → PR → review, dengan aktor dan waktu.
- UI `/riwayat` dan `/riwayat/[caseId]`; 7 test Playwright aktif lulus (mock).
- `test_history_returns_the_full_case_chain` dan test terkait lulus.

**Kekurangan:**

- Hanya satu event push per backlog (`PullRequestReviewService.php:439-448`), sehingga push revisi tidak terlihat.
- Status backlog pada event `backlog_created` adalah status sekarang, bukan snapshot saat creation.
- Tidak ada bukti live dengan data nyata dari feedback sampai review.
- Multiple PR sudah di-loop, tetapi multiple push tidak.

**Dampak:** A8 PARTIAL. Untuk audit trail Sprint 3, ini perlu diperbaiki atau didokumentasikan sebagai known limitation.

### 5.8 Merge Sync — PARTIAL

**Yang sudah ada:**

- Webhook `pull_request` dan `pull_request_review` memanggil ulang GitHub untuk state otoritatif.
- `syncIssueStatus` menandai backlog `Selesai` hanya saat `lifecycle_state=merged`; closed tanpa merge tidak dianggap selesai (`PullRequestReviewService.php:678-690`).
- Test webhook: merged → done, closed tanpa merge → tidak done, review submitted/dismissed.
- Endpoint `/sync` memperbarui snapshot.

**Yang belum:**

- Tidak ada webhook live: secret kosong, tidak ada URL publik/tunnel.
- Tidak ada merge nyata di GitHub yang tersinkron ke `Selesai`.
- Tidak ada Playwright/E2E untuk merge sync.
- W14-03 masih menyatakan merge sync “belum ada”, padahal kode sudah ada sejak W14-02; dokumennya stale.

**Dampak:** A9 PARTIAL. Kode siap, bukti live belum.

### 5.9 Kualitas Test dan Artefak — temuan lintas fitur

- **Test legacy menutupi kontrak aktif.** 8 test Playwright `/pull-requests` lulus dengan payload lama, sementara halaman aktif `/pull-request` memakai payload baru. CI terlihat hijau, tetapi sebagian hijau itu tidak relevan.
- **Test count drift.** `WEEK14_IMPLEMENTATION_REPORT.md` menyebut 276 test/275 passed, 14 review test, 14 webhook test. Aktual: 278 test, 277 pass lokal/278 CI, 16 review test, 24 webhook test.
- **Test naming.** Beberapa test menyebut “real GitHub” tetapi memakai `Http::fake`.
- **Tidak ada coverage threshold/static analysis.** Tidak ada bukti coverage PHP/TS atau lint tambahan; kualitas masih bergantung pada test fungsional.
- **Dokumentasi tidak sinkron.** README masih Sprint 1 (31 test), tracker UC04–UC06 “Belum diverifikasi”, dan beberapa kontrak saling bertentangan.

---

## 6. Defect & Gap Log (Draft)

| ID | Severity | Temuan | Bukti | Dampak | Owner | Tindakan wajib |
| --- | --- | --- | --- | --- | --- | --- |
| W15-D01 | **Critical** | Tidak ada PR nyata yang dibuat melalui MaintainGov | Semua test UC05 `Http::fake`; smoke read-only; tidak ada URL PR | A6/E4 gagal; UC06 live tidak bisa mulai | Hanif | Uji tulis di repo disposable; simpan URL PR + metadata |
| W15-D02 | **Critical** | Satu PAT tidak dapat menghasilkan PR dan review oleh dua identitas berbeda | `GitHubService.php:18-19`; token owner `grdviriyandraa`; seed tanpa `github_login` | A7/C1/C2 BLOCKED; risiko identitas review salah | Demas + Hanif + Gerard | Token per user/GitHub App user-to-server, atau workaround dua token yang didokumentasikan |
| W15-D03 | **Critical** | Webhook live belum aktif | `GITHUB_WEBHOOK_SECRET` kosong; tidak ada tunnel | A9 dan verifikasi push webhook tidak terbukti | Hanif | Set secret, tunnel HTTPS, daftarkan event, uji delivery bertanda tangan |
| W15-D04 | **High** | PHP Behat tidak mencakup UC04–UC06 | 9 skenario: auth 6, UC01 2, Sprint2 1 | B1 FAIL; acceptance BDD tidak terpenuhi | Gerard + Demas | Tambah feature UC04/UC05/UC06/status/merge; jalankan di CI |
| W15-D05 | **High** | Playwright live UC02–UC06 tidak ada/tidak reprodusibel | 4 live test skip; `tools/sprint2_real_e2e.py` tidak ada; `sprint3-live` read-only | B6/C1 tidak terbukti | Valisha | Tambah spec live full journey + setup state yang commit-able |
| W15-D06 | **High** | 8 test Playwright menargetkan halaman legacy dan kontrak lama | `sprint2-pull-request-review.spec.ts:103,131,176`; `/pull-requests` vs `/pull-request` | CI hijau menyesatkan; regresi kontrak tidak terdeteksi | Valisha | Hapus/redirect legacy atau tandai `@legacy`; test kontrak aktif saja |
| W15-D07 | **High** | Python real-artifact test gagal lokal | 17 pass, 3 fail; `sentiment_pipeline.joblib` hilang | B5 PARTIAL; kualitas model nyata tidak terbukti | Gerard | Pulihkan artefak privat, jalankan 20/20; bedakan sintetis vs nyata |
| W15-D08 | **High** | Tidak ada commit/PR/artefak W15 | Branch = `main`; tidak ada `docs/evidence/w15` sebelum laporan ini | E1/E6/E7 gagal; tidak ada target SHA W15 | Gerard | Commit test/evidence, buat PR, tautkan ke issue #51 |
| W15-D09 | **Medium** | Riwayat kehilangan push revisi dan snapshot status | `PullRequestReviewService.php:417-448` hanya satu `push_detected` | A8 tidak lengkap; audit trail revisi tidak akurat | Demas | Simpan event push per SHA/waktu atau tabel histori |
| W15-D10 | **Medium** | FR-UC06-05 (hasil checks) tidak ada | UI hanya `fetchChangedFiles`; tidak ada endpoint checks | Ketidaksesuaian SRS | Demas + Valisha | Implementasi atau catat known limitation dengan persetujuan |
| W15-D11 | **Medium** | Dokumen kontrak/guide saling bertentangan dan ada snippet fiktif | `GITHUB_API_CONTRACT...:235-275`; `GITHUB_AUTH_PERMISSION_GUIDE.md:51-58`; `GITHUB_INTEGRATION_AUDIT.md:32,56` | Salah desain/test; PIC/tim bisa mengejar kontrak yang salah | Gerard + Demas | Rekonsiliasi dokumen; satu sumber kebenaran `contracts/uc06-api.md` |
| W15-D12 | **Medium** | Route legacy `/pull-requests` masih aktif tanpa role gate dan mengirim payload lama | `pull-requests/page.tsx`; `sprint2/client.ts:601-604` | User yang tahu URL mendapat 422/403; kebingungan | Valisha | Redirect ke `/pull-request` atau hapus |
| W15-D13 | **Medium** | Klaim jumlah test tidak konsisten | W14 report 276/275, 14 review, 14 webhook vs aktual 278/277, 16, 24 | Bukti tidak dapat diaudit ulang | Gerard | Perbarui angka + lampirkan log |
| W15-D14 | **Low** | MySQL lokal tidak aktif; SQLite skip CHECK | `migrate:status` connection refused; 1 skipped | Bukti MySQL lokal tidak ada | Demas | Nyalakan MySQL 8, jalankan 278/278, simpan log |
| W15-D15 | **Low** | README/tracker stale; artefak untracked | README 31 test; tracker “Belum diverifikasi”; 2 JSON untracked | Status proyek menyesatkan | Gerard | Perbarui README/tracker; putuskan artefak JSON |

---

## 7. Doc–Code Drift

| Dokumen | Klaim | Realitas kode | Dampak |
| --- | --- | --- | --- |
| `docs/GITHUB_API_CONTRACT_UC04_UC05_UC06.md` §3.4 | UC06 review oleh **Tim Maintenance SAJA**; decision `APPROVE`/`REQUEST_CHANGES`; field `notes`; response 201; error `REVIEW_ROLE_FORBIDDEN`, `GITHUB_REVIEWER_IDENTITY_MISMATCH` | Route `role:Developer`; decision `approve`/`request_changes`; field `comment`; response 200; error `REVIEW_AUTHOR_NOT_ALLOWED`; tidak ada identity guard | Kontrak dan test legacy mengikuti dokumen yang salah |
| `docs/GITHUB_AUTH_PERMISSION_GUIDE.md:51-58` | Snippet `GITHUB_REVIEWER_IDENTITY_MISMATCH` “di `PullRequestReviewService.php` line 698” | Tidak ada kode tersebut; file/service tidak mengimplementasikan guard | Membuat pembaca mengira identity mismatch sudah aman |
| `docs/GITHUB_INTEGRATION_AUDIT.md:32,56` | Identity mismatch guard; FR-UC06-01..18; frontend `/pull-requests` | Tidak ada guard; SRS hanya 16 FR; halaman aktif `/pull-request` | Audit sebelumnya overclaim |
| `docs/WEEK14_IMPLEMENTATION_REPORT.md` | UC04–UC06 **COMPLETE**; 276 test/275 pass; 14 review test; 14 webhook test; frontend `/pull-requests` untuk Tim Maintenance | Live write BLOCKED; 278 test/277 pass lokal; 16 review test; 24 webhook test; halaman aktif Developer `/pull-request` | Status “COMPLETE” tidak didukung bukti |
| `docs/evidence/w14-03/w14-03-report.md` | “Live” 2/2 Playwright; PR #9001/#9002; merge sync belum ada | “Live” hanya Laravel nyata + GitHub **tiruan**; nomor PR tidak ada di GitHub; merge sync sudah ada sejak W14-02 | Bukti E2E tidak valid untuk AC live |
| `docs/evidence/w14/w14-01-report.md` | 252 test/251 pass/1 skip; 15 review test | Snapshot historis; sekarang 278/277/1 dan 16 review test | Jangan dikutip sebagai status saat ini |
| `README.md` / `docs/PROGRESS_TRACKER.md` | Sprint 1: 31 test; UC04–UC06 “Belum diverifikasi”; burndown kosong | Baseline sudah 278 test; UC04–UC06 implemented | Status fitur dan acceptance tidak akurat |

**Sumber kebenaran yang disarankan:** `contracts/uc06-api.md` + route/controller aktual. Dokumen lain harus direvisi atau diberi label “historical”.

---

## 8. Blockers dan Handoff

| ID | Blocker | Owner | Unblock action | Exit criteria |
| --- | --- | --- | --- | --- |
| BLK-01 | Tidak ada bukti tulis GitHub (branch/PR) | Hanif | Siapkan repo disposable + branch uji; jalankan `POST /pulls` nyata; simpan URL | PR URL + metadata + log respons 201 |
| BLK-02 | Satu PAT untuk dua identitas | Demas + Hanif | Implementasi token per user/GitHub App user-to-server; untuk demo sementara: dua PAT + restart/config switch | PR author dan review author adalah dua login berbeda di GitHub |
| BLK-03 | Webhook live | Hanif | Set `GITHUB_WEBHOOK_SECRET`; tunnel HTTPS; event `push`, `pull_request`, `pull_request_review` | Delivery 200 bertanda tangan; merge → backlog `Selesai` |
| BLK-04 | Playwright live tidak reprodusibel | Valisha | Commit setup state/script; spec full journey; env terdokumentasi | 1 test full journey lulus + trace/video |
| BLK-05 | Artefak model nyata | Gerard | Pulihkan `sentiment_pipeline.joblib` (privat); jalankan pytest | 20/20 lulus dengan artefak nyata; sintetis tetap tercatat terpisah |
| BLK-06 | MySQL lokal | Demas | Jalankan MySQL 8; `migrate:fresh --seed`; test MySQL | 278/278 lulus; log tersimpan |
| BLK-07 | Artefak acceptance W15 | Gerard | Test report, defect log, regression checklist, tracker, burndown, internal acceptance | Checklist issue #51 terisi; bukti tertaut |

**Handoff ke W15-02:** staging tidak bisa dinyatakan siap sebelum BLK-01/02/03 selesai. W15-02 membutuhkan URL staging, health check, dan smoke test; saat ini belum ada URL staging yang dapat diaudit.

---

## 9. Rencana Eksekusi W15 yang Disarankan

### P0 — Wajib sebelum menyatakan W15-01 selesai

1. **Buat commit W15 dan target SHA.**
   - Commit test/evidence ke branch `feature/W15-01-backend-testing`.
   - Buka PR ke `main`, tautkan issue #51.
   - Pastikan CI berjalan pada SHA PR, bukan hanya `main`.

2. **Siapkan identitas GitHub ganda.**
   - Minimal dua akun GitHub nyata: `author-dev` dan `reviewer-dev`.
   - Daftarkan keduanya sebagai collaborator repo uji.
   - Untuk jangka pendek: dua PAT (`GITHUB_TOKEN_AUTHOR`, `GITHUB_TOKEN_REVIEWER`) dan mekanisme switch/restart; untuk solusi benar: GitHub App user-to-server token atau PAT terenkripsi per user.
   - Isi `pengguna_sistem.github_login` untuk kedua Developer.
   - Pastikan reviewer != author, baik di database lokal maupun di GitHub.

3. **Buat PR nyata melalui MaintainGov.**
   - Gunakan repo disposable `MaintainGov-E2E` (disarankan) atau branch disposable di repo utama.
   - Login Maintenance → upload CSV → validasi → backlog → login Developer A → claim → push branch → verifikasi push → create PR.
   - Simpan **URL PR** dan nomor PR. Ini bukti E4.

4. **Lakukan review nyata.**
   - Switch ke token reviewer; login Developer B.
   - Approve satu PR; Request Changes pada PR lain (atau ulangi dengan PR baru).
   - Pastikan review di GitHub tercatat atas nama `reviewer-dev`, bukan `author-dev`.
   - Simpan URL review.
   - Uji re-review setelah push baru/PR baru.

5. **Aktifkan webhook dan uji merge sync.**
   - `cloudflared tunnel --url http://127.0.0.1:8000` atau ngrok.
   - Set `GITHUB_WEBHOOK_SECRET` sama dengan secret repo.
   - Daftarkan event `push`, `pull_request`, `pull_request_review`.
   - Merge PR di GitHub; pastikan backlog `Selesai` dan `/riwayat` menampilkan event.
   - Uji juga PR closed tanpa merge: backlog **tidak** `Selesai`.

6. **Lengkapi Behat UC04–UC06.**
   - Tambah feature: claim/role, push verify/stale, create PR/idempotent/403, approve/request changes/self-review/non-open, history, merge sync.
   - Tag `@live`; jalankan pada stack nyata; simpan output pretty/JSON.
   - Minimal: satu happy path dan satu alternatif per UC.

7. **Jalankan ulang seluruh lapisan dan simpan log.**
   - PHPUnit SQLite + MySQL 8.
   - Behat.
   - Playwright mocked + live full journey.
   - Python pytest dengan artefak nyata.
   - Typecheck + build.
   - CI pada SHA W15.

8. **Terbitkan artefak acceptance.**
   - Test result report (matrix pass/fail/blocked).
   - Defect log dengan status perbaikan.
   - Regression checklist yang ditandatangani.
   - Update `PROGRESS_TRACKER.md`: status fitur, bukti, burndown final.
   - Internal acceptance note (tanggal, peserta, keputusan).
   - Tautkan semua ke issue #51 dan PR.

### P1 — Setelah blocker utama

- Hapus/redirect halaman legacy `/pull-requests`; hapus test legacy dari suite utama atau beri tag `@legacy`.
- Hapus dead code `PullRequestController::review()` dan `SubmitPullRequestReviewRequest` yang tidak punya route.
- Rekonsiliasi dokumen kontrak; jadikan `contracts/uc06-api.md` satu-satunya sumber.
- Implementasi FR-UC06-05 atau catat known limitation dengan persetujuan reviewer.
- Perbaiki history agar setiap push/revisi tercatat.
- Tambah coverage threshold dan static analysis (PHPStan/Larastan, ESLint) bila waktu memungkinkan.

### P2 — Menuju W15-02

- Deploy staging, health check, smoke test.
- UAT dengan PO; catat acceptance.
- Performance/security check; rotasi token; audit log.

---

## 10. Setup Data untuk Live E2E (Rekomendasi Konkret)

### 10.1 Repositori

- Gunakan repo terpisah `AgilePemdigi-2026/MaintainGov-E2E` (disarankan) agar merge test tidak mengotori `main` produk.
- Aktifkan Issues, default branch `main`, branch protection off untuk periode uji.
- Arahkan `GITHUB_REPOSITORY_OWNER`/`GITHUB_REPOSITORY_NAME` ke repo uji.
- Jika tetap memakai repo utama: gunakan branch `e2e/w15-<timestamp>`, PR draft, dan merge hanya file dokumentasi uji; bersihkan setelahnya.

### 10.2 Akun dan seed

| Peran | Email lokal | Role | `github_login` | Token |
| --- | --- | --- | --- | --- |
| Author | `author.dev@maintaingov.test` | Developer | login GitHub author | PAT author |
| Reviewer | `reviewer.dev@maintaingov.test` | Developer | login GitHub reviewer | PAT reviewer |
| Maintenance | `maintenance@maintaingov.test` | Tim Maintenance | tidak wajib | — |

- Jangan memakai akun `developer@maintaingov.test` tanpa `github_login` untuk live test.
- Pastikan password test kuat dan tidak di-commit.
- Seed harus idempoten dan tidak menghapus data produksi.

### 10.3 Skenario E2E minimum

1. Login Maintenance.
2. Upload CSV valid; tunggu batch `Selesai`.
3. Buka case `Pending`; validasi `Ya`; edit backlog; status `Ready for Development`.
4. Logout; login Author.
5. Claim issue; branch `maintenance/issue-<id>` dibuat.
6. Push commit nyata ke branch (di luar app, dengan kredensial author).
7. Verifikasi push di app; status `Ready for Pull Request`.
8. Create PR via app; simpan URL PR.
9. Logout; login Reviewer (token reviewer).
10. Approve; cek GitHub review author = reviewer.
11. Buat PR kedua (atau push revisi); Request Changes dengan komentar; cek backlog `Sedang Dikerjakan`.
12. Push perbaikan; verifikasi; buat PR baru; reviewer Approve.
13. Merge PR di GitHub; tunggu webhook; cek backlog `Selesai` dan `/riwayat`.
14. Cek PR closed tanpa merge pada kasus lain: backlog tidak `Selesai`.

### 10.4 Perintah operasional

```powershell
# API
cd apps/api
php artisan migrate:fresh --seed --force
php artisan config:clear
php artisan serve --host=127.0.0.1 --port=8000

# Worker (terminal terpisah)
cd apps/api
php artisan queue:work --sleep=1 --tries=1 --timeout=180

# Web
cd apps/web
npm run build
npm run start

# Analysis
python -m uvicorn main:app --app-dir services/analysis --host 127.0.0.1 --port 8001

# Tunnel webhook (contoh)
cloudflared tunnel --url http://127.0.0.1:8000
```

Setelah mengganti token/secret, **selalu** `php artisan config:clear` dan restart worker; config Laravel di-cache.

---

## 11. Regression Checklist W15

Checklist ini dapat langsung dipakai untuk regression setelah perbaikan.

| # | Skenario | Expected | Status awal |
| ---: | --- | --- | --- |
| 1 | Login Maintenance valid | 200, dashboard | PASS |
| 2 | Login Developer valid | 200, dashboard | PASS |
| 3 | Login salah | 401/422 generik | PASS |
| 4 | Logout lalu akses endpoint terproteksi | 401 | PASS |
| 5 | Developer akses analisis/UC02 | 403 | PASS |
| 6 | Tim Maintenance akses review PR | 403 | PASS |
| 7 | Upload CSV valid | Batch `Selesai`, case `Pending` | PASS |
| 8 | Upload CSV invalid | 422, tidak ada case parsial | PASS |
| 9 | Upload duplikat | Idempoten, tidak duplikat | PASS |
| 10 | Service analisis mati | Batch `Gagal`, retry berhasil | PASS |
| 11 | Validasi case `Ya` | Backlog `Ready for Development` | PASS |
| 12 | Validasi case `Tidak` | Tidak ada backlog | PASS |
| 13 | Edit backlog valid | Tersimpan setelah reload | PASS |
| 14 | Edit backlog invalid | 422, form tetap | PASS |
| 15 | Claim issue | Assigned, status `Sedang Dikerjakan` | PASS |
| 16 | Claim bersamaan | Satu menang, satu 409 | PASS (unit) |
| 17 | Push verify SHA valid | `Ready for Pull Request` | PASS (fake) |
| 18 | Push verify SHA stale | Ditolak | PASS (unit) |
| 19 | Create PR valid | 201, metadata tersimpan | PARTIAL (fake) |
| 20 | Create PR duplikat | 200 replay, satu PR | PASS (fake) |
| 21 | GitHub 403/503 saat create PR | Tidak ada sukses palsu | PASS (fake) |
| 22 | Approve PR | `Ready for Merge`, review synced | PASS (fake) |
| 23 | Request Changes | Backlog `Sedang Dikerjakan`, komentar wajib | PASS (fake) |
| 24 | Self-review | 403 | PASS (fake) |
| 25 | Review PR non-Open | 409 | PASS (fake) |
| 26 | Review duplikat reviewer sama | Upsert satu baris | PASS (fake) |
| 27 | Sync review gagal | Review `gagal`, PR tetap Open, warning | PASS (fake) |
| 28 | Riwayat lengkap | Urutan case → review, aktor/waktu | PARTIAL |
| 29 | Merge webhook | Backlog `Selesai` | PASS (fake webhook) |
| 30 | Closed tanpa merge | Backlog tidak `Selesai` | PASS (fake webhook) |
| 31 | Playwright Sprint 1 | 5/5 | PASS (CI) |
| 32 | Playwright Sprint 2/3 mocked | 43/43 lokal, 48 CI | PASS |
| 33 | Typecheck + build | Lulus | PASS |
| 34 | Python pytest nyata | 20/20 | BLOCKED |
| 35 | Live full journey | PR + review + merge | BLOCKED |

---

## 12. Evidence Checklist

| Bukti | Status | Lokasi / catatan |
| --- | --- | --- |
| CI run pada SHA | **ADA** | Run 44556777490, SHA `967f29d`, 5/5 job |
| Log PHPUnit SQLite | **ADA** | Hasil audit: 278/277/1, 1.477 assertions |
| Log PHPUnit MySQL | **ADA (CI)** | 278/278, 1.478 assertions; log job CI |
| Log Behat | **ADA (CI)** | 9/9, 41 langkah; cakupan belum UC01–UC06 |
| Laporan Playwright | **ADA** | 43/43 lokal mocked; CI 48/48 + 4 skip |
| Trace/video Playwright live | **TIDAK ADA** | Live test belum dijalankan |
| Log Python pytest | **ADA** | Lokal 17/3; CI 20/20 sintetis |
| Typecheck/build | **ADA** | Lokal + CI |
| GitHub read smoke | **ADA** | `github:check`, `smoke-uc05` read-only |
| URL PR nyata | **TIDAK ADA** | Wajib dibuat di W15 |
| URL review nyata | **TIDAK ADA** | Wajib dibuat di W15 |
| Webhook delivery log | **TIDAK ADA** | Secret/tunnel belum ada |
| Bukti merge sync | **TIDAK ADA** | Hanya fake webhook |
| Screenshot/rekaman W15 | **TIDAK ADA** | Screenshot W14-03 memakai fake GitHub |
| Test matrix | **DRAFT** | Laporan ini |
| Defect log | **DRAFT** | Laporan ini |
| Regression checklist | **DRAFT** | Laporan ini |
| Internal acceptance | **TIDAK ADA** | Belum ada rapat/checklist |
| Update tracker/burndown | **TIDAK ADA** | Masih kosong/stale |
| PR + commit SHA W15 | **TIDAK ADA** | Branch belum punya commit |

---

## 13. Definition of Done untuk W15-01

W15-01 dapat dinyatakan **Done** hanya jika seluruh kondisi berikut terpenuhi:

- [ ] CI lulus pada SHA PR W15 (bukan hanya `main`).
- [ ] PHPUnit MySQL 8: 278/278 (atau jumlah baru) lulus, log terlampir.
- [ ] PHP Behat memiliki dan menjalankan skenario UC01–UC06, termasuk minimal satu alternatif per UC.
- [ ] Playwright live full journey lulus: login → CSV → case → backlog → claim → push → PR → review → merge → riwayat.
- [ ] Ada URL PR nyata yang dibuat melalui MaintainGov dan URL review nyata.
- [ ] Review author di GitHub adalah reviewer yang berbeda dari author PR.
- [ ] Webhook bertanda tangan terbukti; merge → `Selesai`; closed tanpa merge tidak `Selesai`.
- [ ] Python pytest lulus dengan artefak model nyata; hasil sintetis dipisahkan.
- [ ] Typecheck + production build lulus.
- [ ] Test result report, defect log, regression checklist tersedia dan ditautkan.
- [ ] `PROGRESS_TRACKER.md` diperbarui; burndown final diisi.
- [ ] Internal acceptance dicatat; issue #51 dan PR ditautkan.
- [ ] Tidak ada critical bug terbuka pada alur utama.

---

## 14. Kesimpulan

**W15-01 belum selesai dan belum dapat diterima sebagai Sprint 3 acceptance.** Baseline otomatis proyek kuat — 278 test PHP, 9 Behat, 48 Playwright, 20 test FastAPI sintetis, dan CI 5/5 hijau — tetapi kekuatan itu sebagian besar berada di lapisan **unit/contract/mock**, bukan di lapisan **live integration** yang menjadi inti W15-01.

Tiga blocker yang harus diselesaikan lebih dulu:

1. **PR nyata** melalui MaintainGov, dengan bukti URL dan metadata.
2. **Identitas GitHub ganda** untuk author dan reviewer (token per user/GitHub App), bukan satu PAT.
3. **Webhook live** dengan secret dan tunnel untuk merge sync.

Setelah itu, Behat UC04–UC06, Playwright live full journey, Python dengan artefak nyata, dan artefak acceptance W15 harus dilengkapi. Selama tiga blocker di atas belum selesai, status yang benar adalah **BLOCKED**, bukan **PASS**.

---

## Lampiran A — Inventaris Test

### A.1 PHPUnit

- Feature: 23 file, 244+ metode test, 278 test aktual.
- Unit: `GitHubServiceTest` 26, `ExampleTest` 1.
- Skip lokal: `SchemaSprint1Test` CHECK constraint MySQL.

### A.2 PHP Behat

- `auth_sprint1.feature` — 6 skenario.
- `uc01_sprint1.feature` — 2 skenario.
- `sprint2_live.feature` — 1 skenario.
- Total 9 skenario, 41 langkah.

### A.3 Playwright

- 8 spec file, 52 deklarasi test.
- 48 run by default; 4 live opt-in skip.
- 8 test legacy `/pull-requests`; 7 test aktif `/pull-request` + `/riwayat`.

### A.4 Python

- `services/analysis/tests` — 20 test.
- `tests/test_contracts.py` + `tests/test_human_evaluation.py` — 13 test.
- Behave: `api_sprint1` 9, `analyze_feedback` 9, `authentication` 4.

---

## Lampiran B — Perintah Reproduksi

```powershell
# PHPUnit lokal (SQLite)
cd apps/api
php artisan test --compact

# PHPUnit MySQL (setelah MySQL 8 aktif)
$env:DB_CONNECTION="mysql"
$env:DB_DATABASE="maintaingov_w15"
$env:DB_USERNAME="root"
$env:DB_PASSWORD=""
php artisan migrate:fresh --seed --force
php artisan test --compact

# Behat (stack API harus hidup)
php -d memory_limit=512M vendor/behat/behat/bin/behat --format=pretty

# Playwright mocked
cd ../web
npm run typecheck
npm run build
npx playwright test

# Playwright live (setelah env dan data siap)
$env:MAINTAINGOV_SPRINT3_LIVE="1"
$env:MAINTAINGOV_E2E_PASSWORD="<test-password>"
$env:MAINTAINGOV_E2E_PR_ID="<id>"
$env:MAINTAINGOV_E2E_CASE_ID="<id>"
npx playwright test sprint3-live.spec.ts

# Python
cd ../services/analysis
python -m pytest -q

# GitHub read-only
cd ../../apps/api
php artisan github:check
php artisan github:smoke-uc05 --require-write
```

---

**Catatan penutup:** laporan ini adalah audit read-only. Tidak ada kode aplikasi yang diubah. Perbaikan, penambahan test, pembuatan PR, dan perubahan konfigurasi token/webhook adalah pekerjaan W15 yang harus dilakukan oleh PIC terkait dan diverifikasi ulang setelah perbaikan.
