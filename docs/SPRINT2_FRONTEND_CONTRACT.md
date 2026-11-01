# Sprint 2: kontrak API yang dipakai frontend (draf v0.1)

Status: **draf usulan frontend** untuk UC02–UC05, diturunkan dari [ARCHITECTURE.md](ARCHITECTURE.md) §4–6. Pemilik kontrak final: Axel (UC02/UC03, skema) dan Hanif (UC04/UC05, GitHub). Bila kontrak final berbeda, cukup ubah `apps/web/src/lib/sprint2/types.ts` dan `client.ts`; komponen halaman tidak perlu diubah.

Semua path berawalan `/api/v1`, memakai sesi Laravel (cookie + `X-CSRF-TOKEN` untuk non-GET). Error mengikuti ARCHITECTURE §4: 401 tanpa sesi, 403 role tidak berhak, 409 konflik status/klaim, 422 input salah, 502/503 kegagalan GitHub.

| Method/path | Request | Respons | Role | Use case |
| --- | --- | --- | --- | --- |
| GET `/maintenance-cases?status=&search=&kategori=&severity=&page=` | query | `{ data: MaintenanceCase[], meta }` | Maintenance | UC02 |
| GET `/maintenance-cases/{id}` | - | `{ data: MaintenanceCaseDetail }` (dengan `reviews[]`) | Maintenance | UC02 |
| POST `/maintenance-cases/{id}/validation` | `{ keputusan: "Valid" \| "Tidak Valid" }` | `{ data: { case, backlog \| null } }`; 409 bila bukan Pending | Maintenance | UC02 |
| GET `/maintenance-backlogs?status=&search=&modul=&severity=&page=` | query | `{ data: MaintenanceBacklog[], meta }` | Kedua role | UC03/UC04 |
| GET `/maintenance-backlogs/{id}` | - | `{ data: MaintenanceBacklog }` | Kedua role | UC03/UC04 |
| PATCH `/maintenance-backlogs/{id}` | `BacklogUpdate` | `{ data }`; 409 bila sudah dikerjakan; 422 field wajib | Maintenance | UC03 |
| POST `/maintenance-backlogs/{id}/claim` | - | `{ data }` status Sedang Dikerjakan; 409 `Issue telah diambil Developer lain` | Developer | UC04 |
| GET `/maintenance-backlogs/{id}/pull-request-options` | - | `{ data: { source_branch, target_branches[], default_target, repository } }`; 409 bila belum Ready for PR | Developer assignee | UC05 (**endpoint baru, usulan**) |
| POST `/maintenance-backlogs/{id}/pull-requests` | `{ target_branch, judul, deskripsi }` | `{ data: PullRequestInfo }` status Open; 503 bila GitHub gagal | Developer assignee | UC05 |

Field lengkap ada di `apps/web/src/lib/sprint2/types.ts`. Catatan:

- `Maintenance Issue` = backlog dalam konteks pengerjaan (glossary SRS), sehingga UC04/UC05 memakai resource `maintenance-backlogs`.
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
