// UC06 (Sprint 2): uji UI daftar/detail Pull Request, approve, request changes,
// identity mismatch, sync, dan role gating lewat mock API. Bukan bukti live.
import { test, expect } from "@playwright/test";
import { USERS, mockApi, paged } from "./sprint2-helpers";

const PR_URL = "https://github.com/AgilePemdigi-2026/MaintainGov/pull/42";
const HEAD_SHA = "abcdef1234567890abcdef1234567890abcdef12";

function summary(overrides: Record<string, unknown> = {}) {
  return {
    ...detail(),
    reviews_count: 0,
    ...overrides,
  };
}

function detail(overrides: Record<string, unknown> = {}) {
  return {
    pull_request_id: 5,
    issue_id: 7,
    backlog_id: 7,
    developer_id: USERS.developer.user_id,
    author: { user_id: USERS.developer.user_id, nama: USERS.developer.nama, github_login: "raditya-dev" },
    repository: {
      full_name: "AgilePemdigi-2026/MaintainGov",
      owner: "AgilePemdigi-2026",
      name: "MaintainGov",
      html_url: "https://github.com/AgilePemdigi-2026/MaintainGov",
    },
    source_branch: "maintenance/issue-7",
    target_branch: "main",
    title: "Perbaikan login SSO",
    description: "Menambahkan fallback saat gateway Dukcapil timeout.",
    github_pr_number: 42,
    github_pr_url: PR_URL,
    lifecycle_state: "open",
    is_draft: false,
    review_state: "pending",
    merged_at: null,
    closed_at: null,
    github_created_at: "2026-11-01T09:00:00Z",
    github_updated_at: "2026-11-02T10:00:00Z",
    last_synced_at: "2026-11-02T10:00:00Z",
    head_sha: HEAD_SHA,
    base_sha: "1234567890abcdef1234567890abcdef12345678",
    created_at: "2026-11-01T09:00:00Z",
    issue: {
      issue_id: 7,
      backlog_id: 7,
      title: "Login gagal setelah update",
      status: "Ready for Pull Request",
      source_branch: "maintenance/issue-7",
    },
    reviews: [] as unknown[],
    review_summary: { approved: 0, changes_requested: 0, dismissed: 0 },
    ...overrides,
  };
}

function review(overrides: Record<string, unknown> = {}) {
  return {
    code_review_id: 9001,
    github_review_id: 5001,
    reviewer_id: USERS.maintenance.user_id,
    reviewer: { user_id: USERS.maintenance.user_id, nama: USERS.maintenance.nama, github_login: "budi-reviewer" },
    reviewer_login: "budi-reviewer",
    github_state: "APPROVED",
    decision: "approved",
    body: null,
    commit_id: HEAD_SHA,
    html_url: `${PR_URL}#pullrequestreview-5001`,
    source: "maintaingov",
    submitted_at: "2026-11-02T11:00:00Z",
    synced_at: "2026-11-02T11:00:00Z",
    ...overrides,
  };
}

function approvedDetail() {
  return detail({
    title: "Perbaikan login SSO",
    review_state: "approved",
    reviews: [review()],
    review_summary: { approved: 1, changes_requested: 0, dismissed: 0 },
    issue: {
      issue_id: 7,
      backlog_id: 7,
      title: "Login gagal setelah update",
      status: "Ready for Merge",
      source_branch: "maintenance/issue-7",
    },
  });
}

test("list shows pull requests and sends the status filter", async ({ page }) => {
  const mock = await mockApi(page, USERS.maintenance, ({ method, path, query }) => {
    if (method === "GET" && path === "/pull-requests") {
      expect(query.get("status")).toBe("open");
      return { body: paged([summary()]) };
    }
  });

  await page.goto("/pull-requests");

  await expect(page.getByRole("heading", { name: "Pull Request", exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: "Perbaikan login SSO" }).first()).toBeVisible();
  await expect(page.getByText("#42")).toBeVisible();
  await expect(page.getByText("BKLG-0007")).toBeVisible();
  await expect(page.getByText("Menunggu review")).toBeVisible();
  expect(mock.calls.some(call => call.path === "/pull-requests")).toBe(true);
});

test("developer sees pull request detail without review controls", async ({ page }) => {
  await mockApi(page, USERS.developer, ({ method, path }) => {
    if (method === "GET" && path === "/pull-requests/5") return { body: { data: detail() } };
  });

  await page.goto("/pull-requests/5");

  await expect(page.getByRole("heading", { name: "Perbaikan login SSO" })).toBeVisible();
  await expect(page.getByText("Hanya Tim Maintenance yang dapat memberikan keputusan review")).toBeVisible();
  await expect(page.getByRole("button", { name: "Kirim keputusan review" })).toHaveCount(0);
  await expect(page.getByRole("link", { name: "Buka di GitHub" })).toHaveAttribute("href", PR_URL);
  await expect(page.getByText("BKLG-0007").first()).toBeVisible();
});

test("Tim Maintenance approves and the issue becomes Ready for Merge", async ({ page }) => {
  const mock = await mockApi(page, USERS.maintenance, ({ method, path, body }) => {
    if (method === "GET" && path === "/pull-requests/5") return { body: { data: detail() } };
    if (method === "POST" && path === "/pull-requests/5/reviews") {
      expect(body.decision).toBe("APPROVE");
      return {
        status: 201,
        body: {
          message: "Pull Request disetujui. Issue siap untuk merge.",
          data: approvedDetail(),
          meta: {
            review: review(),
            created: true,
            reconciled: false,
            idempotent_replay: false,
            sync_pending: false,
          },
        },
      };
    }
  });

  await page.goto("/pull-requests/5");
  await page.getByRole("radio", { name: "Approve" }).check();
  await page.getByRole("button", { name: "Kirim keputusan review" }).click();

  await expect(page.getByText("Pull Request disetujui. Issue siap untuk merge.")).toBeVisible();
  await expect(page.getByText("Disetujui").first()).toBeVisible();
  await expect(page.getByText("Ready for merge", { exact: true })).toBeVisible();
  const posts = mock.calls.filter(call => call.method === "POST" && call.path === "/pull-requests/5/reviews");
  expect(posts).toHaveLength(1);
});

test("request changes requires notes and sends trimmed notes", async ({ page }) => {
  const changesRequested = detail({
    review_state: "changes_requested",
    reviews: [review({ github_state: "CHANGES_REQUESTED", decision: "changes_requested", body: "Tambahkan test." })],
    review_summary: { approved: 0, changes_requested: 1, dismissed: 0 },
    issue: {
      issue_id: 7,
      backlog_id: 7,
      title: "Login gagal setelah update",
      status: "Sedang Dikerjakan",
      source_branch: "maintenance/issue-7",
    },
  });
  const mock = await mockApi(page, USERS.maintenance, ({ method, path, body }) => {
    if (method === "GET" && path === "/pull-requests/5") return { body: { data: detail() } };
    if (method === "POST" && path === "/pull-requests/5/reviews") {
      expect(body).toEqual({ decision: "REQUEST_CHANGES", notes: "Tambahkan test." });
      return {
        status: 201,
        body: {
          message: "Perubahan memerlukan perbaikan",
          data: changesRequested,
          meta: {
            review: review({ github_state: "CHANGES_REQUESTED", decision: "changes_requested", body: "Tambahkan test." }),
            created: true,
            reconciled: false,
            idempotent_replay: false,
            sync_pending: false,
          },
        },
      };
    }
  });

  await page.goto("/pull-requests/5");
  await page.getByRole("radio", { name: "Request changes" }).check();
  await page.getByRole("button", { name: "Kirim keputusan review" }).click();

  await expect(page.getByText("Catatan perbaikan wajib diisi untuk Request Changes.")).toBeVisible();
  expect(mock.calls.filter(call => call.method === "POST")).toHaveLength(0);

  await page.getByLabel("Catatan review").fill("  Tambahkan test.  ");
  await page.getByRole("button", { name: "Kirim keputusan review" }).click();

  await expect(page.getByText("Perubahan memerlukan perbaikan")).toBeVisible();
  await expect(page.getByText("Perlu perbaikan").first()).toBeVisible();
  const posts = mock.calls.filter(call => call.method === "POST" && call.path === "/pull-requests/5/reviews");
  expect(posts).toHaveLength(1);
});

test("identity mismatch from the server is shown to the reviewer", async ({ page }) => {
  await mockApi(page, USERS.maintenance, ({ method, path }) => {
    if (method === "GET" && path === "/pull-requests/5") return { body: { data: detail() } };
    if (method === "POST" && path === "/pull-requests/5/reviews") {
      return {
        status: 403,
        body: {
          message: "Review GitHub akan tercatat atas nama pemilik token server, bukan reviewer ini. "
            + "Gunakan akun pemilik token atau konfigurasi GitHub App/user token per reviewer.",
        },
      };
    }
  });

  await page.goto("/pull-requests/5");
  await page.getByRole("radio", { name: "Approve" }).check();
  await page.getByRole("button", { name: "Kirim keputusan review" }).click();

  await expect(page.getByText("pemilik token server", { exact: false })).toBeVisible();
  await expect(page.getByText("Pull Request disetujui", { exact: false })).toHaveCount(0);
});

test("sync refreshes the pull request snapshot", async ({ page }) => {
  const mock = await mockApi(page, USERS.maintenance, ({ method, path }) => {
    if (method === "GET" && path === "/pull-requests/5") return { body: { data: detail() } };
    if (method === "POST" && path === "/pull-requests/5/sync") {
      return { body: { data: approvedDetail() } };
    }
  });

  await page.goto("/pull-requests/5");
  await expect(page.getByText("Menunggu review").first()).toBeVisible();
  await page.getByRole("button", { name: "Sinkronkan GitHub" }).click();

  await expect(page.getByText("Pull Request berhasil disinkronkan dengan GitHub.")).toBeVisible();
  await expect(page.getByText("Disetujui").first()).toBeVisible();
  expect(mock.calls.some(call => call.method === "POST" && call.path === "/pull-requests/5/sync")).toBe(true);
});

test("closed pull request cannot be reviewed", async ({ page }) => {
  await mockApi(page, USERS.maintenance, ({ method, path }) => {
    if (method === "GET" && path === "/pull-requests/5") {
      return { body: { data: detail({ lifecycle_state: "closed", closed_at: "2026-11-03T09:00:00Z" }) } };
    }
  });

  await page.goto("/pull-requests/5");

  await expect(page.getByText("Review hanya dapat diberikan pada Pull Request berstatus Open", { exact: false })).toBeVisible();
  await expect(page.getByRole("button", { name: "Kirim keputusan review" })).toHaveCount(0);
});

test("mobile 375x812 shows the pull request list heading", async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await mockApi(page, USERS.developer, ({ method, path }) => {
    if (method === "GET" && path === "/pull-requests") return { body: paged([summary()]) };
  });

  await page.goto("/pull-requests");
  await expect(page.getByRole("heading", { name: "Pull Request", exact: true })).toBeVisible();
});
