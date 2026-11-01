// UC03 Mengedit Maintenance Backlog (Feature 5a + 5b). API di-mock (kontrak v0.1): uji UI + kontrak,
// bukan bukti integrasi live.
import { test, expect } from "@playwright/test";
import { USERS, mockApi, paged } from "./sprint2-helpers";
import type { ApiHandler } from "./sprint2-helpers";

type Backlog = Record<string, any>;

function seed(): Backlog[] {
  return [
    {
      backlog_id: 7, case_id: 192, judul: "Login gagal setelah update", kategori: "Bug", modul: "Autentikasi", fitur: "Login",
      severity: "High", priority_score: 8.2, ringkasan_masalah: "Token sesi tidak diperbarui.", recommendation: "Perbaiki refresh token.",
      status: "Ready for Development", assigned_to: null, assigned_at: null, source_branch: null, last_push: null, pull_request: null,
      created_at: "2026-10-20T08:00:00Z", updated_at: "2026-10-20T08:00:00Z",
    },
    {
      backlog_id: 8, case_id: 193, judul: "Notifikasi terlambat", kategori: "Notification", modul: "Notifikasi", fitur: "Push",
      severity: "Medium", priority_score: 5, ringkasan_masalah: "Antrian lambat.", recommendation: null,
      status: "Sedang Dikerjakan", assigned_to: { user_id: 2, nama: "Raditya Fahmi" }, assigned_at: "2026-10-21T08:00:00Z", source_branch: "fix/notif",
      last_push: null, pull_request: null, created_at: "2026-10-20T09:00:00Z", updated_at: "2026-10-21T08:00:00Z",
    },
  ];
}

function handlerFor(items: Backlog[], extra?: ApiHandler): ApiHandler {
  return async call => {
    if (extra) {
      const custom = await extra(call);
      if (custom) return custom;
    }
    if (call.method === "GET" && call.path === "/maintenance-backlogs") {
      const status = call.query.get("status");
      return { body: paged(status ? items.filter(item => item.status === status) : items) };
    }
    const match = call.path.match(/^\/maintenance-backlogs\/(\d+)$/);
    if (match) {
      const item = items.find(entry => entry.backlog_id === Number(match[1]));
      if (!item) return { status: 404, body: { message: "Tidak ditemukan" } };
      if (call.method === "GET") return { body: { data: item } };
      if (call.method === "PATCH") {
        Object.assign(item, call.body, { updated_at: "2026-10-28T10:00:00Z" });
        return { body: { data: item } };
      }
    }
    return undefined;
  };
}

const heading = (page: import("@playwright/test").Page) => page.getByRole("heading", { name: "Maintenance Backlog", exact: true });

test("Maintenance melihat daftar dan filter status mengirim query", async ({ page }) => {
  const items = seed();
  const mock = await mockApi(page, USERS.maintenance, handlerFor(items));
  await page.goto("/maintenance-backlog");
  await expect(heading(page)).toBeVisible();
  await expect(page.getByText("2 tiket tervalidasi")).toBeVisible();
  await expect(page.getByRole("link", { name: "Login gagal setelah update" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Notifikasi terlambat" })).toBeVisible();
  await page.getByLabel("Status", { exact: true }).selectOption("Sedang Dikerjakan");
  await expect(page.getByRole("link", { name: "Login gagal setelah update" })).toHaveCount(0);
  await expect(page.getByRole("link", { name: "Notifikasi terlambat" })).toBeVisible();
  expect(mock.calls.some(call => call.path === "/maintenance-backlogs")).toBe(true);
});

test("Developer melihat daftar tetapi detail tanpa tombol Edit", async ({ page }) => {
  await mockApi(page, USERS.developer, handlerFor(seed()));
  await page.goto("/maintenance-backlog");
  await expect(heading(page)).toBeVisible();
  await page.goto("/maintenance-backlog/7");
  await expect(page.getByRole("heading", { name: "Login gagal setelah update", exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Edit", exact: true })).toHaveCount(0);
  await expect(page.getByText("Hanya Tim Maintenance yang dapat mengubah backlog.")).toBeVisible();
});

test("edit valid menyimpan, menampilkan pesan sukses, dan data bertahan setelah reload", async ({ page }) => {
  const items = seed();
  const mock = await mockApi(page, USERS.maintenance, handlerFor(items));
  await page.goto("/maintenance-backlog/7");
  await page.getByRole("button", { name: "Edit", exact: true }).click();
  await expect(page.getByLabel("Judul", { exact: false }).first()).toHaveValue("Login gagal setelah update");
  await page.getByLabel("Judul", { exact: false }).first().fill("Login gagal setelah pembaruan v2");
  await page.getByRole("button", { name: "Simpan perubahan", exact: true }).click();
  await expect(page.getByText("Perubahan berhasil disimpan")).toBeVisible();
  await expect(page.getByRole("button", { name: "Edit", exact: true })).toBeVisible();
  const patches = mock.calls.filter(call => call.method === "PATCH");
  expect(patches).toHaveLength(1);
  expect(patches[0].path).toBe("/maintenance-backlogs/7");
  expect(patches[0].body.judul).toBe("Login gagal setelah pembaruan v2");
  await page.reload();
  await expect(page.getByRole("heading", { name: "Login gagal setelah pembaruan v2", exact: true })).toBeVisible();
});

test("field wajib kosong menampilkan error dan tidak memanggil API", async ({ page }) => {
  const items = seed();
  const mock = await mockApi(page, USERS.maintenance, handlerFor(items));
  await page.goto("/maintenance-backlog/7");
  await page.getByRole("button", { name: "Edit", exact: true }).click();
  await page.getByLabel("Modul", { exact: false }).first().fill("   ");
  await page.getByLabel("Judul", { exact: false }).first().fill("Judul sementara");
  await page.getByRole("button", { name: "Simpan perubahan", exact: true }).click();
  await expect(page.getByText("Data wajib belum lengkap")).toBeVisible();
  await expect(page.getByText("Periksa kolom yang ditandai merah sebelum menyimpan.")).toBeVisible();
  await expect(page.getByLabel("Modul", { exact: false }).first()).toHaveAttribute("aria-invalid", "true");
  await expect(page.getByLabel("Judul", { exact: false }).first()).toHaveValue("Judul sementara");
  expect(mock.calls.filter(call => call.method === "PATCH")).toHaveLength(0);
  await expect(page.getByRole("button", { name: "Simpan perubahan", exact: true })).toBeVisible();
});

test("klik ganda Simpan hanya mengirim satu PATCH", async ({ page }) => {
  const items = seed();
  const mock = await mockApi(page, USERS.maintenance, handlerFor(items, async call => {
    if (call.method === "PATCH") await new Promise(resolve => setTimeout(resolve, 400));
    return undefined;
  }));
  await page.goto("/maintenance-backlog/7");
  await page.getByRole("button", { name: "Edit", exact: true }).click();
  await page.getByLabel("Fitur", { exact: false }).first().fill("Login SSO");
  await page.getByRole("button", { name: "Simpan perubahan", exact: true }).dblclick();
  await expect(page.getByText("Perubahan berhasil disimpan")).toBeVisible();
  expect(mock.calls.filter(call => call.method === "PATCH")).toHaveLength(1);
});

test("backlog Sedang Dikerjakan tidak punya tombol Edit", async ({ page }) => {
  await mockApi(page, USERS.maintenance, handlerFor(seed()));
  await page.goto("/maintenance-backlog/8");
  await expect(page.getByRole("heading", { name: "Notifikasi terlambat", exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Edit", exact: true })).toHaveCount(0);
  await expect(page.getByText(/sudah dikerjakan/)).toBeVisible();
});

test("API 422 menjaga form tetap terbuka", async ({ page }) => {
  const items = seed();
  await mockApi(page, USERS.maintenance, handlerFor(items, call => (
    call.method === "PATCH" ? { status: 422, body: { message: "Skor prioritas tidak valid dari server." } } : undefined
  )));
  await page.goto("/maintenance-backlog/7");
  await page.getByRole("button", { name: "Edit", exact: true }).click();
  await page.getByLabel("Judul", { exact: false }).first().fill("Judul baru");
  await page.getByRole("button", { name: "Simpan perubahan", exact: true }).click();
  await expect(page.getByText("Data wajib belum lengkap")).toBeVisible();
  await expect(page.getByText("Skor prioritas tidak valid dari server.")).toBeVisible();
  await expect(page.getByRole("button", { name: "Simpan perubahan", exact: true })).toBeEnabled();
  await expect(page.getByLabel("Judul", { exact: false }).first()).toHaveValue("Judul baru");
});

test("500 pada daftar menampilkan error dan Coba lagi memuat ulang", async ({ page }) => {
  let fail = true;
  const items = seed();
  await mockApi(page, USERS.maintenance, handlerFor(items, call => (
    fail && call.method === "GET" && call.path === "/maintenance-backlogs" ? { status: 500, body: { message: "" } } : undefined
  )));
  await page.goto("/maintenance-backlog");
  await expect(page.getByText("Data gagal dimuat")).toBeVisible();
  fail = false;
  await page.getByRole("button", { name: "Coba lagi", exact: true }).click();
  await expect(page.getByRole("link", { name: "Login gagal setelah update" })).toBeVisible();
});

test("mobile 375x812 menampilkan heading daftar", async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await mockApi(page, USERS.maintenance, handlerFor(seed()));
  await page.goto("/maintenance-backlog");
  await expect(heading(page)).toBeVisible();
});
