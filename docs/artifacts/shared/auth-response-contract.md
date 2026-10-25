# Kontrak Respons API Autentikasi (W10-05)

Dokumen ini menjelaskan format respons API untuk kebutuhan integrasi frontend (W10-06).

## Konfigurasi Frontend
- **Base URL:** `http://localhost:8000/api/v1`
- **Session:** API ini menggunakan **Cookie-based Session**.
- **Credentials:** Frontend wajib mengirim `withCredentials: true` (Axios) atau `credentials: 'include'` (Fetch).
- **CSRF:** Untuk request state-changing (POST/PUT/DELETE), frontend harus mengambil token dari cookie `XSRF-TOKEN` dan mengirimkannya via header `X-XSRF-TOKEN`.

---

## 1. POST `/auth/login`
Membuat sesi pengguna baru.

**Request Body:**
```json
{
  "email": "tim@maintaingov.test",
  "password": "password123",
  "remember": false
}

{
  "success": true,
  "message": "Login berhasil.",
  "data": {
    "user_id": 1,
    "nama": "Budi Tim Maintenance",
    "email": "tim@maintaingov.test",
    "role": "Tim Maintenance"
  }
}

{
  "success": false,
  "message": "Email atau password salah."
}

{
  "message": "The email field is required.",
  "errors": {
    "email": ["The email field is required."]
  }
}

{
  "success": true,
  "data": {
    "user_id": 1,
    "nama": "Budi Tim Maintenance",
    "email": "tim@maintaingov.test",
    "role": "Tim Maintenance"
  }
}

{
  "success": false,
  "message": "Akses ditolak. Silakan login terlebih dahulu."
}

{
  "success": true,
  "message": "Logout berhasil."
}

{
  "success": false,
  "message": "Akses ditolak. Role tidak memiliki izin."
}