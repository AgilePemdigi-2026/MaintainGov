// UC04/UC05 (Sprint 2): uji UI terhadap kontrak v0.1 lewat mock API. Bukan bukti integrasi live.
import { test, expect } from "@playwright/test";
import { USERS, mockApi, paged } from "./sprint2-helpers";

const dev = { user_id: USERS.developer.user_id, nama: USERS.developer.nama };
const other = { user_id: 99, nama: "Sari Lestari" };
const PR_URL = "https://github.com/maintaingov/app/pull/42";

function issue(overrides: Record<string, unknown> = {}) {
  return {
    backlog_id: 7,
    case_id: 192,
    judul: "Login gagal setelah update",
    kategori: "Bug",
    modul: "Autentikasi",
    fitur: "Login",
    severity: "High",
    priority_score: 8.2,
    ringkasan_masalah: "Pengguna tidak dapat masuk setelah pembaruan.",
    recommendation: null,
    status: "Ready for Development",
    assigned_to: null,
    assigned_at: null,
    source_branch: null,
    last_push: null,
    pull_request: null,
    created_at: "2026-10-26T08:00:00Z",
    updated_at: "2026-10-26T08:00:00Z",
    ...overrides,
  };
}

const readyForPr = () => issue({
  status: "Ready for Pull Request",
  assigned_to: dev,
  assigned_at: "2026-10-27T08:00:00Z",
  source_branch: "feature/login-fix",
  last_push: { sha: "abcdef1234567", branch: "feature/login-fix", pushed_at: "2026-10-28T09:00:00Z" },
});

const options = {
  source_branch: "feature/login-fix",
  target_branches: ["main", "develop"],
  default_target: "develop",
  repository: "maintaingov/app",
};

test("list shows Ready for Development issues", async ({ page }) => {
  const mock = await mockApi(page, USERS.developer, ({ method, path, query }) => {
    if (method === "GET" && path === "/maintenance-backlogs") {
      expect(query.get("status")).toBe("Ready for Development");
      return { body: paged([issue()]) };
    }
  });
  await page.goto("/maintenance-issue");
  await expect(page.getByRole("heading", { name: "Maintenance Issue", exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: "Login gagal setelah update" })).toBeVisible();
  await expect(page.getByText("BKLG-0007")).toBeVisible();
  expect(mock.calls.some(call => call.path === "/maintenance-backlogs")).toBe(true);
});

test("claim success updates status and assignee, POST sent once on double click", async ({ page }) => {
  let current = issue();
  const mock = await mockApi(page, USERS.developer, async ({ method, path }) => {
    if (method === "GET" && path === "/maintenance-backlogs/7") return { body: { data: current } };
    if (method === "POST" && path === "/maintenance-backlogs/7/claim") {
      await new Promise(resolve => setTimeout(resolve, 300));
      current = issue({ status: "Sedang Dikerjakan", assigned_to: dev, assigned_at: "2026-10-27T08:00:00Z" });
      return { body: { data: current } };
    }
  });
  await page.goto("/maintenance-issue/7");
  await expect(page.getByRole("heading", { name: "Login gagal setelah update" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Ajukan pull request" })).toBeDisabled();
  await page.getByRole("button", { name: "Kerjakan issue" }).dblclick();
  await expect(page.getByText("Sedang dikerjakan").first()).toBeVisible();
  await expect(page.getByText(USERS.developer.nama).first()).toBeVisible();
  await expect(page.getByRole("button", { name: "Kerjakan issue" })).toBeDisabled();
  expect(mock.calls.filter(call => call.method === "POST" && call.path === "/maintenance-backlogs/7/claim")).toHaveLength(1);
});

test("claim 409 shows taken message and locks the button", async ({ page }) => {
  let taken = false;
  await mockApi(page, USERS.developer, ({ method, path }) => {
    if (method === "GET" && path === "/maintenance-backlogs/7") {
      return { body: { data: taken ? issue({ status: "Sedang Dikerjakan", assigned_to: other, assigned_at: "2026-10-27T08:00:00Z" }) : issue() } };
    }
    if (method === "POST" && path === "/maintenance-backlogs/7/claim") {
      taken = true;
      return { status: 409, body: { message: "Issue telah diambil Developer lain" } };
    }
  });
  await page.goto("/maintenance-issue/7");
  await page.getByRole("button", { name: "Kerjakan issue" }).click();
  await expect(page.getByText("Issue telah diambil Developer lain")).toBeVisible();
  await expect(page.getByText(other.nama).first()).toBeVisible();
  await expect(page.getByRole("button", { name: "Kerjakan issue" })).toBeDisabled();
});

test("Tim Maintenance sees access denied", async ({ page }) => {
  await mockApi(page, USERS.maintenance);
  await page.goto("/maintenance-issue");
  await expect(page.getByText("Akses ditolak")).toBeVisible();
});

test("PR form: empty submit shows incomplete message and sends no POST", async ({ page }) => {
  const mock = await mockApi(page, USERS.developer, ({ method, path }) => {
    if (method === "GET" && path === "/maintenance-backlogs/7") return { body: { data: readyForPr() } };
    if (method === "GET" && path === "/maintenance-backlogs/7/pull-request-options") return { body: { data: options } };
  });
  await page.goto("/maintenance-issue/7/pull-request");
  await expect(page.getByRole("heading", { name: "Buat Pull Request" })).toBeVisible();
  await page.getByRole("button", { name: "Buat pull request" }).click();
  await expect(page.getByText("Lengkapi informasi Pull Request")).toBeVisible();
  await expect(page.getByLabel(/Judul pull request/)).toHaveAttribute("aria-invalid", "true");
  expect(mock.calls.filter(call => call.method === "POST")).toHaveLength(0);
});

test("PR form: success shows result with GitHub link and exact body", async ({ page }) => {
  const mock = await mockApi(page, USERS.developer, ({ method, path, body }) => {
    if (method === "GET" && path === "/maintenance-backlogs/7") return { body: { data: readyForPr() } };
    if (method === "GET" && path === "/maintenance-backlogs/7/pull-request-options") return { body: { data: options } };
    if (method === "POST" && path === "/maintenance-backlogs/7/pull-requests") {
      return {
        status: 201,
        body: {
          data: {
            pr_id: 1, github_pr_number: 42, url: PR_URL, judul: body.judul,
            source_branch: "feature/login-fix", target_branch: body.target_branch,
            status: "Open", created_at: "2026-10-29T09:00:00Z",
          },
        },
      };
    }
  });
  await page.goto("/maintenance-issue/7/pull-request");
  await page.getByLabel(/Judul pull request/).fill("fix(auth): perbaiki login");
  await page.getByLabel(/Deskripsi perubahan/).fill("Perbaikan login.");
  await page.getByRole("button", { name: "Buat pull request" }).click();
  await expect(page.getByText("Pull request berhasil dibuat")).toBeVisible();
  await expect(page.getByText("Open", { exact: true })).toBeVisible();
  await expect(page.getByText("#42")).toBeVisible();
  await expect(page.getByRole("link", { name: PR_URL })).toHaveAttribute("href", PR_URL);
  const post = mock.calls.filter(call => call.method === "POST");
  expect(post).toHaveLength(1);
  expect(post[0].body).toEqual({ target_branch: "develop", judul: "fix(auth): perbaiki login", deskripsi: "Perbaikan login." });
});

test("PR form: GitHub 503 shows connection message and no success", async ({ page }) => {
  await mockApi(page, USERS.developer, ({ method, path }) => {
    if (method === "GET" && path === "/maintenance-backlogs/7") return { body: { data: readyForPr() } };
    if (method === "GET" && path === "/maintenance-backlogs/7/pull-request-options") return { body: { data: options } };
    if (method === "POST" && path === "/maintenance-backlogs/7/pull-requests") {
      return { status: 503, body: { message: "Koneksi GitHub tidak tersedia" } };
    }
  });
  await page.goto("/maintenance-issue/7/pull-request");
  await page.getByLabel(/Judul pull request/).fill("fix(auth): perbaiki login");
  await page.getByLabel(/Deskripsi perubahan/).fill("Perbaikan login.");
  await page.getByRole("button", { name: "Buat pull request" }).click();
  await expect(page.getByText("Koneksi GitHub tidak tersedia")).toBeVisible();
  await expect(page.getByText("Pull request berhasil dibuat")).toHaveCount(0);
  await expect(page.getByLabel(/Judul pull request/)).toHaveValue("fix(auth): perbaiki login");
});

test("mobile 375x812 shows the issue list heading", async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await mockApi(page, USERS.developer, ({ method, path }) => {
    if (method === "GET" && path === "/maintenance-backlogs") return { body: paged([issue()]) };
  });
  await page.goto("/maintenance-issue");
  await expect(page.getByRole("heading", { name: "Maintenance Issue", exact: true })).toBeVisible();
});
