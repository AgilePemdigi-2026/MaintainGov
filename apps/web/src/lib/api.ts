const BASE_URL =
  process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000/api/v1";

export class ApiError extends Error {
  constructor(
    public status: number,
    public body: string,
  ) {
    super(`API error ${status}`);
  }
}

// Frontend hanya berbicara dengan Laravel API; sesi memakai cookie (credentials: include).
// TODO: kirim header CSRF setelah mekanisme Sanctum dipasang di backend.
export async function apiFetch<T>(
  path: string,
  init: RequestInit = {},
): Promise<T> {
  const res = await fetch(`${BASE_URL}${path}`, {
    ...init,
    credentials: "include",
    headers: { Accept: "application/json", ...init.headers },
  });
  if (!res.ok) throw new ApiError(res.status, await res.text());
  if (res.status === 204) return undefined as T;
  return (await res.json()) as T;
}
