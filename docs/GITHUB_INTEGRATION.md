# Integrasi GitHub — Feature 9

Status: implementasi read-only selesai untuk bagian yang tidak membutuhkan kredensial GitHub. **Live authenticated check masih BLOCKED** karena `GITHUB_TOKEN` belum tersedia di environment lokal. Dokumen ini menjelaskan implementasi aktual, batas operasi tulis, kontrak UC04–UC05, dan langkah verifikasi.

Acuan resmi:

- SRS §4.6 UC04 “Mengerjakan Maintenance Issue” (FR-UC04-01..11; BR-13..15).
- SRS §4.7 UC05 “Mengajukan Pull Request” (FR-UC05-01..14; BR-16..18).
- [Arsitektur](ARCHITECTURE.md) §4, §5, §8 dan [Delta PDM Sprint 1](artifacts/shared/pdm-delta-sprint1.md).
- Kontrak endpoint dan fixture: [contracts/uc04-uc05-api.md](../contracts/uc04-uc05-api.md).

## 1. Alur koneksi

```mermaid
flowchart LR
    FE[Next.js / browser]
    API[Laravel API]
    CONN[GitHubConnectionController]
    SVC[GitHubService adapter]
    MOCK[GitHubMockController UC04-UC05]
    FIX[(Fixture deterministik)]
    GH[GitHub REST API]
    DEV[Developer: git push di luar MaintainGov]

    FE -->|session cookie + CSRF| API
    API --> CONN
    CONN --> SVC
    SVC -->|GET /repos/owner/repo| GH
    SVC -->|Bearer token dari env server| GH
    GH -->|metadata / 401 / 403 / 429 / 5xx| SVC
    SVC -->|status internal yang aman| CONN
    CONN -->|JSON tanpa token| FE

    FE -.->|endpoint /developer/github-mock| MOCK
    MOCK -.->|baca| FIX
    MOCK -.->|JSON bertanda is_mock| FE
    MOCK -.->|TIDAK memanggil GitHub| GH

    DEV -.->|event push/PR| GH
    GH -.->|webhook belum diimplementasikan| API
```

Frontend tidak pernah menerima token GitHub. Token hanya dibaca oleh Laravel dari environment server. Garis putus-putus menunjukkan mock/stub dan alur yang belum aktif; tidak ada operasi tulis GitHub yang dijalankan oleh fitur ini.

## 2. Konfigurasi

Tambahkan variabel berikut pada `apps/api/.env` lokal atau secret manager. Jangan commit nilai token.

| Variabel | Default | Keterangan |
| --- | --- | --- |
| `GITHUB_API_URL` | `https://api.github.com` | Base URL GitHub REST API. |
| `GITHUB_REPOSITORY_OWNER` | `AgilePemdigi-2026` | Owner repository utama. |
| `GITHUB_REPOSITORY_NAME` | `MaintainGov` | Nama repository utama. |
| `GITHUB_TOKEN` | kosong | Token server-side untuk operasi baca. Wajib untuk pemeriksaan terautentikasi. |
| `GITHUB_TIMEOUT` | `10` | Timeout request (detik). |
| `GITHUB_CONNECT_TIMEOUT` | `5` | Timeout koneksi (detik). |
| `GITHUB_MOCK_ENABLED` | aktif saat `local`/`testing` | Mengaktifkan route mock UC04–UC05. Route mengembalikan 404 saat nonaktif. |

Implementasi saat ini memakai **Bearer token** (`GITHUB_TOKEN`). Ini dapat berupa fine-grained personal access token dengan izin baca metadata repository, atau mekanisme token lain yang disetujui tim. **GitHub App JWT/installation-token exchange belum diimplementasikan** karena kredensial App dan izinnya belum diverifikasi (D09). Jangan menganggap App sudah aktif.

Izin minimum yang dituju untuk pemeriksaan ini adalah **repository metadata: read**. Izin tulis (contents write, pull request write) tidak diminta dan tidak dipakai oleh fitur ini. Izin aktual baru dapat dinyatakan terverifikasi setelah live check berhasil dengan token.

Jika token belum tersedia, adapter tidak mengirim request dan mengembalikan status aman `unavailable` dengan kode `GITHUB_NOT_CONFIGURED`.

## 3. Endpoint pemeriksaan koneksi

`GET /api/v1/github/connection`

- Autentikasi: sesi Laravel; role `Tim Maintenance` atau `Developer`.
- Implementasi: **nyata**, read-only `GET /repos/{owner}/{repo}`.
- Tidak ada parameter.
- Respons sukses: HTTP 200, status internal `connected`.
- Respons gagal memakai `success=false`, `message`, `error.code`, `error.message`, dan `data.status`.

| Status internal | Kondisi | HTTP | Kode error |
| --- | --- | --- | --- |
| `connected` | Repository berhasil dibaca | 200 | — |
| `unauthorized` | GitHub merespons 401 | 401 | `GITHUB_UNAUTHORIZED` |
| `forbidden` | GitHub merespons 403 dan bukan rate limit | 403 | `GITHUB_FORBIDDEN` |
| `rate_limited` | 429, atau 403 dengan header/body rate limit | 429 | `GITHUB_RATE_LIMITED` |
| `not_found` | 404 (repository tidak ada atau tidak dapat diakses) | 404 | `GITHUB_REPOSITORY_NOT_FOUND` |
| `unavailable` | Timeout, jaringan, 5xx, respons tidak valid, atau konfigurasi belum lengkap | 503 | `GITHUB_UNAVAILABLE`, `GITHUB_INVALID_RESPONSE`, `GITHUB_NOT_CONFIGURED`, `GITHUB_UNEXPECTED_STATUS` |

Rate limit tidak disimpulkan dari semua respons 403. Adapter memeriksa `X-RateLimit-Remaining: 0`, `Retry-After`, pesan “rate limit”/“secondary rate limit”, dan `documentation_url` yang relevan. Untuk rate limit, `error.retry_after_seconds` dan header `Retry-After` diisi bila GitHub menyediakannya.

Contoh sukses ada di [`contracts/fixtures/uc04-uc05/connection-success.json`](../contracts/fixtures/uc04-uc05/connection-success.json). Contoh error tersedia untuk unauthorized, forbidden, rate-limited, unavailable, not-found, dan not-configured pada folder fixture yang sama.

## 4. Pengujian live read-only

Perintah manual:

```bash
cd apps/api
php artisan github:check
```

Perilaku:

- Token tersedia dan repository terbaca: mencetak `PASS`, metadata repository, dan permission yang benar-benar dikembalikan GitHub. Exit code `0`.
- Token belum tersedia: mencetak `BLOCKED` tanpa request jaringan. Exit code `1`.
- Gagal autentikasi/otorisasi/rate limit/jaringan: mencetak status aman tanpa token. Exit code `1`.

Token tidak pernah dicetak. Jangan memakai unit test mock sebagai bukti koneksi live.

**Hasil pemeriksaan 1 November 2026:**

- `php artisan github:check` → **BLOCKED**: `GITHUB_TOKEN` belum dikonfigurasi; tidak ada request jaringan.
- Pemeriksaan publik tambahan tanpa autentikasi ke `https://api.github.com/repos/AgilePemdigi-2026/MaintainGov` → HTTP 200, `full_name=AgilePemdigi-2026/MaintainGov`, `default_branch=main`, `private=false`, waktu `2026-11-01T12:33:20Z`.
- Pemeriksaan publik ini hanya membuktikan repository dapat dibaca tanpa token. **Status autentikasi dan permissions tetap BLOCKED.**
- Tidak ada operasi tulis, commit, branch, atau PR yang dilakukan.

## 5. Kontrak UC04–UC05 dan mock backend

Kontrak lengkap: [contracts/uc04-uc05-api.md](../contracts/uc04-uc05-api.md). Ringkasan route mock:

| Method/path | Kegunaan | Tipe |
| --- | --- | --- |
| `GET /developer/github-mock/issues` | Daftar issue/backlog dan filter status | mock |
| `GET /developer/github-mock/issues/{id}` | Detail issue, repository, branch sumber, push | mock |
| `POST /developer/github-mock/issues/{id}/claim` | Klaim issue oleh Developer | mock |
| `GET /developer/github-mock/issues/{id}/push-status` | Status push yang diterima dari GitHub | mock read-only |
| `GET /developer/github-mock/issues/{id}/branches` | Branch sumber/tujuan | mock |
| `GET /developer/github-mock/issues/{id}/pull-requests` | Status PR (termasuk empty state) | mock |
| `POST /developer/github-mock/issues/{id}/pull-requests` | Persiapan pembuatan PR | stub, **tidak membuat PR nyata** |

Semua respons mock memiliki header `X-Mock-Data: true` dan `meta.is_mock=true`. Tidak ada penyimpanan database dan tidak ada panggilan GitHub. `github_pr_number` dan `github_pr_url` sengaja `null` pada stub agar UI tidak menampilkan tautan PR palsu. Mock dijalankan hanya jika `GITHUB_MOCK_ENABLED` aktif; default nonaktif di production.

Stub PR tidak menggantikan pemeriksaan koneksi. Frontend tetap wajib memanggil `GET /github/connection` sebelum menampilkan formulir (FR-UC05-03). Jika gagal, tampilkan persis “Koneksi GitHub tidak tersedia, pengajuan belum diproses” dan jangan mengubah status issue (FR-UC05-04). Karena endpoint koneksi bersifat nyata, alur sukses end-to-end tetap membutuhkan `GITHUB_TOKEN`; mock hanya membantu membangun state UI.

Operasi push tidak dibuat sebagai endpoint tulis karena SRS §4.6.1 menyatakan perubahan source code dilakukan di luar MaintainGov. `push-status` hanya membaca status mock. Integrasi webhook push/PR belum diimplementasikan.

## 6. Batas implementasi dan keputusan terbuka

- Live authenticated check membutuhkan `GITHUB_TOKEN` yang sah; belum tersedia.
- GitHub App belum diverifikasi; mekanisme token saat ini tidak boleh diklaim sebagai App.
- Kontrak mock memakai namespace sementara `/developer/github-mock` agar tidak bertabrakan dengan route UC02/UC03. Path final mengikuti arsitektur (`/maintenance-backlogs/...`) setelah tabel dan kontrak UC03 disepakati.
- Tabel `maintenance_backlog`, `github_repository`, `pull_request`, dan `code_review` belum ada. Kebutuhan field ada di [GITHUB_DATABASE_HANDOFF.md](GITHUB_DATABASE_HANDOFF.md); belum ada migration yang dibuat.
- Webhook signature, deduplikasi delivery ID, pencarian PR setelah timeout, dan operasi tulis PR adalah pekerjaan lanjutan, bukan bagian dari Feature 9 ini.
- Status `Ready for Merge`, `Closed`, dan `Merged` serta pemetaan reviewer GitHub (UC06) belum diimplementasikan.

## 7. Pengujian otomatis

```bash
cd apps/api
php artisan test --compact tests/Unit/GitHubServiceTest.php
php artisan test --compact tests/Feature/GitHubConnectionTest.php
php artisan test --compact tests/Feature/GitHubMockContractTest.php
```

Seluruh unit test adapter memakai `Http::fake`/`Http::preventStrayRequests`, sehingga tidak membutuhkan internet atau token. Feature test memakai sesi Laravel dan mock HTTP yang sama.

## 8. Langkah QA untuk Gerard

Tujuan: memeriksa kontrak dan perilaku UI tanpa kredensial GitHub. Langkah ini **bukan** bukti koneksi live dan tidak boleh dilaporkan sebagai integrasi GitHub nyata.

Prasyarat:

- `APP_ENV=local` (atau `testing`) dan `GITHUB_MOCK_ENABLED=true` di `.env` lokal. Jangan aktifkan mock di production.
- Tersedia akun `Developer` dan `Tim Maintenance`.
- Frontend mengirim session cookie dan header `X-CSRF-TOKEN` untuk request `POST`.

Langkah:

1. Tanpa sesi, buka `GET /api/v1/github/connection` dan salah satu route mock. Pastikan hasilnya HTTP 401 dan tidak ada data repository.
2. Login sebagai `Developer`, lalu buka `GET /api/v1/github/connection`. Tanpa `GITHUB_TOKEN`, pastikan HTTP 503, `data.status=unavailable`, `error.code=GITHUB_NOT_CONFIGURED`, dan tidak ada token di body maupun DevTools. Jika dipicu dari alur Ajukan PR, UI harus menampilkan “Koneksi GitHub tidak tersedia, pengajuan belum diproses” dan status issue tidak berubah (FR-UC05-04).
3. Buka `GET /api/v1/developer/github-mock/issues`. Pastikan HTTP 200, header `X-Mock-Data: true`, `meta.is_mock=true`, tiga issue, dan tidak ada request ke `api.github.com`.
4. Uji filter `?status=Ready%20for%20Merge`; pastikan `data=[]` dan `meta.total=0`, bukan error.
5. `POST /issues/101/claim`; pastikan HTTP 200, status berubah menjadi `Sedang Dikerjakan` pada respons, ada pesan bahwa data tidak benar-benar diubah, dan refresh daftar tetap menunjukkan kondisi mock awal.
6. `POST /issues/103/claim`; pastikan HTTP 409 `ISSUE_ALREADY_ASSIGNED` dengan pesan SRS.
7. Buka `GET /issues/102/branches` dan `GET /issues/102/pull-requests`; pastikan data berlabel mock dan `github_pr_number`/`github_pr_url` bernilai `null`.
8. `POST /issues/102/pull-requests` dengan body valid; pastikan HTTP 201, pesan “tidak dibuat di GitHub”, dan tidak ada tautan PR palsu di UI. UI harus menampilkan penanda mock, bukan notifikasi “Pull Request berhasil dibuat” seolah PR nyata; notifikasi sukses SRS hanya boleh muncul setelah integrasi GitHub nyata berhasil.
9. Login sebagai `Tim Maintenance`; pastikan route mock menghasilkan HTTP 403. Route koneksi harus tetap dapat diakses kedua role.
10. Set `GITHUB_MOCK_ENABLED=false`, bersihkan config cache, lalu ulangi route mock; pastikan HTTP 404.
11. Periksa Network tab: tidak boleh ada request langsung dari browser ke GitHub dan tidak boleh ada nilai token pada request/response.
12. Setelah `GITHUB_TOKEN` tersedia, jalankan `php artisan github:check`. Baru setelah itu status autentikasi dan permission boleh dicatat sebagai terverifikasi.

Bukti yang perlu dicatat: tanggal/waktu, role yang dipakai, HTTP status, `error.code`, header `X-Mock-Data`, dan ada/tidaknya request ke GitHub. Jangan memakai screenshot mock sebagai bukti PR atau push nyata.
