import { test, expect } from "@playwright/test";
import { USERS, mockApi, paged, type ApiHandler } from "./sprint2-helpers";

// UC02 Memvalidasi Maintenance Case. API dimock (kontrak v0.1), bukan bukti integrasi live.
const CASE_VALID = "Maintenance Case berhasil divalidasi";
const CASE_INVALID = "Maintenance Case tidak diteruskan";

type Status = "Pending" | "Valid" | "Tidak Valid";

function makeCase(id: number, judul: string, modul: string, status: Status = "Pending") {
  return {
    case_id: id,
    judul_issue: judul,
    kategori: "Bug",
    modul,
    fitur: "Fitur " + modul,
    severity: "High",
    priority_score: 8.2,
    ringkasan_masalah: "Ringkasan untuk " + judul,
    recommendation: "Periksa alur " + modul,
    status_validasi: status as Status,
    validated_at: null as string | null,
    validator: null as { user_id: number; nama: string } | null,
    created_at: "2026-10-26T08:00:00Z",
    review_count: 1,
    backlog_id: null as number | null,
    reviews: [
      { review_id: id * 10, source_review_id: "rv-" + id, teks_ulasan: "Aplikasi sering gagal login", rating: 2, sumber: "Play Store", tanggal: "2026-10-25T10:00:00Z" },
    ],
  };
}

function setup() {
  const cases = [
    makeCase(101, "Login gagal setelah update", "Autentikasi"),
    makeCase(102, "Notifikasi terlambat", "Notifikasi"),
  ];
  const queries: URLSearchParams[] = [];
  let validations = 0;
  const handler: ApiHandler = async ({ method, path, query, body }) => {
    if (method === "GET" && path === "/maintenance-cases") {
      queries.push(query);
      const status = query.get("status");
      const search = (query.get("search") ?? "").toLowerCase();
      const rows = cases.filter(c =>
        (!status || c.status_validasi === status) &&
        (!search || (c.judul_issue + c.modul + "CASE-" + c.case_id).toLowerCase().includes(search)));
      return { body: paged(rows) };
    }
    const detail = path.match(/^\/maintenance-cases\/(\d+)$/);
    if (method === "GET" && detail) {
      const found = cases.find(c => c.case_id === Number(detail[1]));
      return found ? { body: { data: found } } : { status: 404, body: { message: "Not found" } };
    }
    const validation = path.match(/^\/maintenance-cases\/(\d+)\/validation$/);
    if (method === "POST" && validation) {
      validations += 1;
      await new Promise(resolve => setTimeout(resolve, 300));
      const found = cases.find(c => c.case_id === Number(validation[1]))!;
      found.status_validasi = body.keputusan;
      found.validated_at = "2026-10-27T09:00:00Z";
      found.validator = { user_id: 1, nama: "Budi Prasetyo" };
      let backlog = null;
      if (body.keputusan === "Valid") {
        found.backlog_id = 501;
        backlog = {
          backlog_id: 501, case_id: found.case_id, judul: found.judul_issue, kategori: found.kategori, modul: found.modul,
          fitur: found.fitur, severity: found.severity, priority_score: found.priority_score,
          ringkasan_masalah: found.ringkasan_masalah, recommendation: found.recommendation, status: "Ready for Development",
          assigned_to: null, assigned_at: null, source_branch: null, last_push: null, pull_request: null,
          created_at: "2026-10-27T09:00:00Z", updated_at: "2026-10-27T09:00:00Z",
        };
      }
      const { reviews: _reviews, ...plain } = found;
      return { body: { data: { case: plain, backlog } } };
    }
    return undefined;
  };
  return { cases, queries, handler, validations: () => validations };
}

test("list renders pending cases and sends search query", async ({ page }) => {
  const state = setup();
  await mockApi(page, USERS.maintenance, state.handler);
  await page.goto("/maintenance-case");
  await expect(page.getByRole("heading", { name: "Maintenance Case", exact: true })).toBeVisible();
  await expect(page.getByText("2 kasus pending hasil ekstraksi feedback")).toBeVisible();
  await expect(page.getByRole("link", { name: "Login gagal setelah update" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Notifikasi terlambat" })).toBeVisible();
  expect(state.queries[0].get("status")).toBe("Pending");

  await page.getByLabel("Cari Maintenance Case").fill("login");
  await expect(page.getByRole("link", { name: "Notifikasi terlambat" })).toHaveCount(0);
  await expect(page.getByRole("link", { name: "Login gagal setelah update" })).toBeVisible();
  expect(state.queries.some(query => query.get("search") === "login")).toBe(true);
});

test("Ya shows confirmation, submits once, and shows backlog", async ({ page }) => {
  const state = setup();
  const mock = await mockApi(page, USERS.maintenance, state.handler);
  await page.goto("/maintenance-case/101");
  await expect(page.getByRole("heading", { name: "Login gagal setelah update", level: 1 })).toBeVisible();
  await expect(page.getByText("Aplikasi sering gagal login")).toBeVisible();
  await expect(page.getByText("Apakah Maintenance Case ini valid untuk diteruskan menjadi Maintenance Backlog?")).toBeVisible();

  await page.getByRole("button", { name: "Ya", exact: true }).click();
  await expect(page.getByText("Konfirmasi: teruskan sebagai Valid?")).toBeVisible();
  await page.getByRole("button", { name: "Konfirmasi", exact: true }).dblclick();

  await expect(page.getByText(CASE_VALID, { exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: "Lihat Maintenance Backlog" })).toHaveAttribute("href", "/maintenance-backlog/501");
  await expect(page.getByRole("button", { name: "Ya", exact: true })).toHaveCount(0);

  const posts = mock.calls.filter(call => call.method === "POST" && call.path === "/maintenance-cases/101/validation");
  expect(posts).toHaveLength(1);
  expect(posts[0].body).toEqual({ keputusan: "Valid" });
  expect(state.validations()).toBe(1);
});

test("Tidak shows the not-forwarded message and the case leaves the pending list", async ({ page }) => {
  const state = setup();
  await mockApi(page, USERS.maintenance, state.handler);
  await page.goto("/maintenance-case/102");
  await page.getByRole("button", { name: "Tidak", exact: true }).click();
  await page.getByRole("button", { name: "Konfirmasi", exact: true }).click();
  await expect(page.getByText(CASE_INVALID, { exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: "Lihat Maintenance Backlog" })).toHaveCount(0);

  await page.getByRole("navigation", { name: "Breadcrumb" }).getByRole("link", { name: "Maintenance Case" }).click();
  await expect(page.getByRole("link", { name: "Login gagal setelah update" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Notifikasi terlambat" })).toHaveCount(0);
});

test("409 on validation shows message and reloads the case", async ({ page }) => {
  const state = setup();
  await mockApi(page, USERS.maintenance, async call => {
    if (call.method === "POST" && call.path === "/maintenance-cases/101/validation") {
      state.cases[0].status_validasi = "Tidak Valid";
      return { status: 409, body: { message: "Maintenance Case sudah divalidasi" } };
    }
    return state.handler(call);
  });
  await page.goto("/maintenance-case/101");
  await page.getByRole("button", { name: "Ya", exact: true }).click();
  await page.getByRole("button", { name: "Konfirmasi", exact: true }).click();
  await expect(page.getByText("Maintenance Case sudah divalidasi")).toBeVisible();
  await expect(page.getByRole("button", { name: "Ya", exact: true })).toHaveCount(0);
  await expect(page.getByText(/Diputuskan/)).toBeVisible();
});

test("unknown case shows not-found state", async ({ page }) => {
  await mockApi(page, USERS.maintenance, setup().handler);
  await page.goto("/maintenance-case/999");
  await expect(page.getByText("Maintenance Case tidak ditemukan").first()).toBeVisible();
});

test("Developer is denied access", async ({ page }) => {
  await mockApi(page, USERS.developer, setup().handler);
  await page.goto("/maintenance-case");
  await expect(page.getByText("Akses ditolak")).toBeVisible();
});

test("unauthenticated user is redirected to login", async ({ page }) => {
  await mockApi(page, null, setup().handler);
  await page.goto("/maintenance-case");
  await expect(page).toHaveURL(/\/login/);
});

test("list API error shows retry that recovers", async ({ page }) => {
  const state = setup();
  let fail = true;
  await mockApi(page, USERS.maintenance, async call => {
    if (call.method === "GET" && call.path === "/maintenance-cases" && fail) return { status: 500, body: { message: "" } };
    return state.handler(call);
  });
  await page.goto("/maintenance-case");
  await expect(page.getByText("Data gagal dimuat")).toBeVisible();
  fail = false;
  await page.getByRole("button", { name: "Coba lagi" }).click();
  await expect(page.getByRole("link", { name: "Login gagal setelah update" })).toBeVisible();
});

test("mobile viewport renders list heading", async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await mockApi(page, USERS.maintenance, setup().handler);
  await page.goto("/maintenance-case");
  await expect(page.getByRole("heading", { name: "Maintenance Case", exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: "Login gagal setelah update" })).toBeVisible();
});
