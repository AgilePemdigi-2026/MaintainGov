const BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://127.0.0.1:8000/api/v1";

export class ApiError extends Error {
  constructor(public status: number, message: string) { super(message); }
}

export async function apiFetch<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  if (!["GET", "HEAD"].includes((init.method ?? "GET").toUpperCase())) {
    const csrf = await fetch(`${BASE_URL}/auth/csrf`, { credentials: "include", cache: "no-store" });
    if (!csrf.ok) throw new ApiError(csrf.status, "Tidak dapat menyiapkan sesi. Coba masuk kembali.");
    headers.set("X-CSRF-TOKEN", (await csrf.json()).token);
  }
  const res = await fetch(`${BASE_URL}${path}`, { ...init, headers, credentials: "include", cache: "no-store" });
  const body = res.status === 204 ? undefined : await res.json().catch(() => ({}));
  if (!res.ok) {
    const details = body.errors ? Object.values(body.errors).flat().join(" ") : "";
    throw new ApiError(res.status, details || body.message || "Permintaan gagal. Silakan coba kembali.");
  }
  return body as T;
}
