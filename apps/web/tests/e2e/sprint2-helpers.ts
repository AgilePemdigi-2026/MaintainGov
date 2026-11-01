// Helper Playwright Sprint 2: mencegat request browser ke Laravel (/api/v1/**) dengan
// respons kontrak v0.1, sehingga UI bisa diuji sebelum endpoint Axel/Hanif tersedia.
// Ini uji kontrak + UI, BUKAN bukti integrasi live; jalankan ulang terhadap stack live setelah backend siap.
import type { Page, Request } from "@playwright/test";

export type Role = "Tim Maintenance" | "Developer";

export const USERS: Record<"maintenance" | "developer", { user_id: number; nama: string; email: string; role: Role }> = {
  maintenance: { user_id: 1, nama: "Budi Prasetyo", email: "maintenance@maintaingov.test", role: "Tim Maintenance" },
  developer: { user_id: 2, nama: "Raditya Fahmi", email: "developer@maintaingov.test", role: "Developer" },
};

export interface MockResponse { status?: number; body?: unknown }

/** Handler menerima method, path tanpa prefix /api/v1 (mis. "/maintenance-cases/192"), query, dan body JSON. */
export type ApiHandler = (call: { method: string; path: string; query: URLSearchParams; body: any; request: Request }) =>
  MockResponse | undefined | Promise<MockResponse | undefined>;

export interface ApiMock {
  calls: { method: string; path: string; body: any }[];
}

/**
 * Pasang mock API. `/auth/csrf`, `/auth/me`, `/auth/logout` sudah ditangani; handler lain dipanggil
 * berurutan, handler pertama yang mengembalikan respons dipakai. Request tanpa handler -> 404.
 */
export async function mockApi(page: Page, user: (typeof USERS)[keyof typeof USERS] | null, handler: ApiHandler = () => undefined): Promise<ApiMock> {
  const mock: ApiMock = { calls: [] };
  await page.route(/\/api\/v1\//, async route => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace(/^.*\/api\/v1/, "");
    const method = request.method();
    let body: any = null;
    try { body = request.postDataJSON(); } catch { body = request.postData(); }
    mock.calls.push({ method, path, body });
    const reply = (status: number, payload?: unknown) => route.fulfill({
      status, contentType: "application/json", body: payload === undefined ? "" : JSON.stringify(payload),
    });
    if (path === "/auth/csrf") return reply(200, { token: "test-csrf" });
    if (path === "/auth/me") return user ? reply(200, { data: user }) : reply(401, { message: "Unauthenticated." });
    if (path === "/auth/logout") return reply(204);
    const result = await handler({ method, path, query: url.searchParams, body, request });
    if (result) return reply(result.status ?? 200, result.body);
    return reply(404, { message: `Tidak ada mock untuk ${method} ${path}` });
  });
  return mock;
}

export function paged<T>(data: T[], page = 1, perPage = 10) {
  return { data, meta: { current_page: page, last_page: Math.max(1, Math.ceil(data.length / perPage)), per_page: perPage, total: data.length } };
}
