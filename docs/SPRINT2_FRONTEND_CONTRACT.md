# Sprint 2: kontrak API yang dipakai frontend (draf v0.1)

Status: **draf usulan frontend** untuk UC02–UC05, diturunkan dari [ARCHITECTURE.md](ARCHITECTURE.md) §4–6. Pemilik kontrak final: Axel (UC02/UC03, skema) dan Hanif (UC04/UC05, GitHub). Bila kontrak final berbeda, cukup ubah `apps/web/src/lib/sprint2/types.ts` dan `client.ts`; komponen halaman tidak perlu diubah.

Semua path berawalan `/api/v1`, memakai sesi Laravel (cookie + `X-CSRF-TOKEN` untuk non-GET). Error mengikuti ARCHITECTURE §4: 401 tanpa sesi, 403 role tidak berhak, 409 konflik status/klaim, 422 input salah, 502/503 kegagalan GitHub.

| Method/path | Request | Respons | Role | Use case |
| --- | --- | --- | --- | --- |
| GET `/maintenance-cases?status=&page=&per_page=` | query (default `status=Pending`) | `{ data: MaintenanceCase[], meta }` | Maintenance | UC02 (**live, PR #31**) |
| GET `/maintenance-cases/{id}` | - | `{ data: { case, reviews[] } }` | Maintenance | UC02 (**live, PR #31**) |
| POST `/maintenance-cases/{id}/validate` | `{ keputusan: "Ya" \| "Tidak" }` | `{ data: { case_id, status_validasi, validator_id, validated_at, keputusan, backlog_id } }`; 409 bila bukan Pending | Maintenance | UC02 (**live, PR #31**) |
| GET `/maintenance-backlog?status=&page=&per_page=` | query | `{ data: MaintenanceBacklog[], meta }` (bentuk backend: `judul_issue`, `assignee_nama`, dst.; dipetakan oleh `client.ts`) | Kedua role | UC03 |
| GET `/maintenance-backlog/{id}` | - | `{ data: MaintenanceBacklog }` | Kedua role | UC03 |
| PATCH `/maintenance-backlog/{id}` | `BacklogUpdate` (`judul`, `modul`, `fitur`, `severity`, `priority_score`, `ringkasan_masalah`, `recommendation`) | `{ data }`; 409 bila sudah dikerjakan; 422 field wajib | Maintenance | UC03 |
| GET `/developer/maintenance-issues?status=&assigned_to_me=&page=&per_page=` | query | `{ data: MaintenanceIssue[], meta }` | Developer | UC04 |
| GET `/developer/maintenance-issues/{id}` | - | `{ data: MaintenanceIssue }` | Developer | UC04 |
| POST `/maintenance-backlogs/{id}/claim` | - | `{ data }` status Sedang Dikerjakan + `branch_preparation` (`created`/`exists`/`failed`); 409 `Issue telah diambil Developer lain` | Developer | UC04 |
| POST `/maintenance-backlogs/{id}/branch` | - | `{ data: BranchPreparation }`; idempotent retry pembuatan branch GitHub; 403 non-assignee, 409 status tidak aktif | Developer assignee | UC04 |
| GET `/maintenance-backlogs/{id}/branches` | query `per_page` | `{ data: { issue_id, source_branch, suggested_target_branch, default_branch, branches[], repository } }`; 409 bila belum Ready for PR | Developer assignee | UC05 |
| POST `/maintenance-backlogs/{id}/pull-requests` | `{ source_branch, target_branch, title, description }` | `{ data: PullRequestInfo }` status Open; 503 bila GitHub gagal | Developer assignee | UC05 |

Field lengkap ada di `apps/web/src/lib/sprint2/types.ts`. Catatan:

- UC02 sudah memakai endpoint asli dari PR #31. Backend hanya memfilter `status`; pencarian, kategori dan severity disaring di sisi klien pada halaman yang dimuat. Opsi status "Semua" tidak tersedia karena backend selalu memfilter satu status.
- Pipeline UC01 menyimpan `severity` dan `kategori` huruf kecil (`medium`, `bug`); UI membandingkan tanpa membedakan huruf besar/kecil.
- Uji live UC02 (1 Nov 2026, stack lokal Laravel + MySQL 8.0.46 + FastAPI model sintetis + worker): upload CSV -> case Pending -> Tidak (`Maintenance Case tidak diteruskan`, tanpa backlog) -> Ya (`Maintenance Case berhasil divalidasi`, backlog terbentuk, 1 POST walau klik ganda) -> case hilang dari daftar Pending -> validasi ulang 409 -> Developer "Akses ditolak". Lolos.

- `Maintenance Issue` = backlog dalam konteks pengerjaan (glossary SRS). UC04 memakai namespace baca `/developer/maintenance-issues`; aksi klaim dan UC05 tetap memakai resource `maintenance-backlogs/{issue}`.
- `last_push` hanya diisi backend setelah push GitHub terverifikasi. UI menampilkan `Ready for Pull Request` dan tombol Ajukan PR berdasarkan status dari API, bukan dari reload halaman (UC04, bukan US04).
- `priority_score` tetap skor 0–10 dari UC01. Label High/Medium/Low di UI adalah **usulan tampilan** (`labels.ts`) sampai keputusan domain prioritas ditutup.
- Pesan wajib UI dipusatkan di `labels.ts` (`MESSAGES`).

## Menjalankan UI

```powershell
cd apps/web
npm ci
# Mode mock (tanpa backend; bukan bukti integrasi live). Ganti role lewat tombol di sidebar.
$env:NEXT_PUBLIC_SPRINT2_MOCK="1"; npm run dev
# Mode live (default): butuh Laravel di 127.0.0.1:8000 dengan endpoint di atas.
npm run dev
```

Uji browser Sprint 2 mencegat `/api/v1/**` dengan respons kontrak di atas (`tests/e2e/sprint2-*.spec.ts`), jadi tidak butuh backend:

```powershell
npm run build; npm run start -- --hostname 127.0.0.1
npx playwright test sprint2-
```

Setelah endpoint Axel/Hanif tersedia, uji yang sama perlu diulang terhadap stack live (Laravel + MySQL uji) sebagai bukti integrasi.
