# Contracts
Folder ini menyimpan kontrak API (JSON Schema, contoh payload) antara komponen MaintainGov.

| File | Kegunaan |
| --- | --- |
| `analysis-request.schema.json` | Kontrak request internal Laravel → FastAPI `POST /internal/v1/analyze` |
| `analysis-response.schema.json` | Kontrak response FastAPI → Laravel |
| `examples/` | Contoh payload request dan response |
| `uc04-uc05-api.md` | Kontrak endpoint koneksi GitHub dan mock UC04–UC05 |
| `uc02-api.md` | Kontrak endpoint peninjauan & validasi case (UC02, W12-01) |
| `uc03-api.md` | Kontrak endpoint maintenance backlog (UC03, W12-02, D-W12-02-01) |
| `fixtures/uc04-uc05/` | Fixture JSON untuk state sukses, empty, unauthorized, forbidden, rate-limited dan unavailable |

Kontrak ini menjadi acuan bagi Demas (Laravel) dan Gerard (FastAPI) agar kedua sisi menghasilkan/menerima format yang sama. Perubahan field harus dibahas bersama sebelum diubah.
