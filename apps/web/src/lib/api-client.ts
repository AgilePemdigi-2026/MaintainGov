const BASE_URL =
process.env.NEXT_PUBLIC_API_BASE_URL ??
"http://localhost:8000/api/v1";

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

export async function apiFetch<T>(
  path: string,
  init: RequestInit = {},
): Promise<T> {
  const method = (init.method ?? "GET").toUpperCase();
  const headers = new Headers(init.headers);

  headers.set("Accept", "application/json");

  if (!["GET", "HEAD", "OPTIONS"].includes(method)) {
    const csrfResponse = await fetch(`${BASE_URL}/auth/csrf`, {
      method: "GET",
      credentials: "include",
      cache: "no-store",
      headers: {
        Accept: "application/json",
      },
    });

    if (!csrfResponse.ok) {
      throw new ApiError(
        csrfResponse.status,
        "Tidak dapat menyiapkan sesi CSRF. Muat ulang halaman dan coba lagi.",
      );
    }

    const csrfBody = (await csrfResponse.json()) as {
      token?: string;
    };

    if (!csrfBody.token) {
      throw new ApiError(419, "Token CSRF tidak ditemukan.");
    }

    headers.set("X-CSRF-TOKEN", csrfBody.token);
  }

  const response = await fetch(`${BASE_URL}${path}`, {
    ...init,
    method,
    headers,
    credentials: "include",
    cache: "no-store",
  });

  const body = response.status === 204
    ? undefined
    : await response.json().catch(() => ({}));

  if (!response.ok) {
    const errorBody = body as {
      errors?: Record<string, string[]>;
      message?: string;
    };

    const details = errorBody.errors
      ? Object.values(errorBody.errors).flat().join(" ")
      : "";

    throw new ApiError(
      response.status,
      details || errorBody.message || "Permintaan gagal. Silakan coba kembali.",
    );
  }

  return body as T;
}