// UC06 + Status/Riwayat: uji kontrak UI terhadap bentuk respons contracts/uc06-api.md.
// Laravel dicegat (lihat sprint2-helpers) — ini BUKAN bukti integrasi live; lihat sprint3-live.spec.ts.
import { test, expect } from "@playwright/test";
import { USERS, mockApi, paged } from "./sprint2-helpers";

const REVIEWER = { user_id: 3, nama: "Reviewer Uji", email: "reviewer@maintaingov.test", role: "Developer" as const };
const AUTHOR = { user_id: 2, nama: "Developer Uji", github_login: "dev-gh" };

function pr(overrides: Record<string, unknown> = {}) {
  return {
    pull_request_id: 1, issue_id: 1, backlog_id: 1, title: "Perbaikan notifikasi",
    source_branch: "maintenance/issue-1", target_branch: "main", status: "open",
    review_status: "Open", review_state: "pending", ready_for_merge: false, status_label: "Open",
    submission_state: "created", lifecycle_state: "open", developer: AUTHOR,
    repository: { full_name: "AgilePemdigi-2026/MaintainGov", owner: "AgilePemdigi-2026", name: "MaintainGov", html_url: "https://github.com/AgilePemdigi-2026/MaintainGov" },
    pull_request_number: 42, pull_request_url: "https://github.com/AgilePemdigi-2026/MaintainGov/pull/42",
    head_sha: "a".repeat(40), base_sha: "c".repeat(40), review_count: 0, last_review: null,
    created_at: "2026-11-14T08:00:00+00:00", updated_at: "2026-11-14T08:00:00+00:00",
    ...overrides,
  };
}

function detail(overrides: Record<string, unknown> = {}) {
  return {
    ...pr(overrides),
    issue: {
      backlog_id: 1, title: "Notifikasi tidak muncul", status: "Ready for Pull Request", assigned_to: AUTHOR,
      case: { case_id: 10, judul_issue: "Notifikasi tidak muncul", kategori: "defect", severity: "High", status_validasi: "Valid", ringkasan_masalah: "Notifikasi update layanan tidak diterima warga." },
    },
    reviews: [],
    ...overrides,
  };
}

const review = (decision: string, sync_status: string, comment: string | null = null) => ({
  review_id: 1, pull_request_id: 1, reviewer: { user_id: 3, nama: "Reviewer Uji", github_login: "reviewer-gh" },
  decision, comment, reviewed_at: "2026-11-14T09:00:00+00:00", sync_status,
  synced_at: sync_status === "synced" ? "2026-11-14T09:00:01+00:00" : null,
  last_sync_error_code: sync_status === "gagal" ? "GITHUB_UNAVAILABLE" : null,
  github_review_id: sync_status === "synced" ? 555001 : null, github_review_url: null,
});

test.beforeEach(async ({ page }) => {
  // Diff dibaca langsung dari GitHub publik; di uji kontrak selalu tidak tersedia.
  await page.route("https://api.github.com/**", route => route.fulfill({ status: 404, body: "{}" }));
});

test("daftar PR menampilkan status dan memfilter lewat ?status=", async ({ page }) => {
  const mock = await mockApi(page, REVIEWER, ({ method, path, query }) => {
    if (method === "GET" && path === "/pull-requests") {
      return { body: paged(query.get("status") === "Approved" ? [pr({ review_status: "Approved", review_state: "approved", ready_for_merge: true, status_label: "Ready for Merge" })] : [pr()]) };
    }
  });
  await page.goto("/pull-request");
  const row = page.getByRole("row", { name: /Perbaikan notifikasi/ });
  await expect(row).toContainText("#42");
  await expect(row).toContainText("Open");
  await expect(row).toContainText("Developer Uji");
  await page.getByLabel("Filter status review").selectOption("Approved");
  await expect(page.getByRole("row", { name: /Perbaikan notifikasi/ })).toContainText("Ready for Merge");
  expect(mock.calls.some(call => call.path === "/pull-requests" && call.method === "GET")).toBe(true);
});

test("detail PR menampilkan issue, author, branch, dan commit", async ({ page }) => {
  await mockApi(page, REVIEWER, ({ method, path }) => {
    if (method === "GET" && path === "/pull-requests/1") return { body: { data: detail() } };
  });
  await page.goto("/pull-request/1");
  await expect(page.getByRole("heading", { name: "Perbaikan notifikasi" })).toBeVisible();
  const info = page.getByRole("region", { name: "Informasi Pull Request" });
  await expect(info).toContainText("Developer Uji");
  await expect(info).toContainText("maintenance/issue-1");
  const issue = page.getByRole("region", { name: "Issue terkait" });
  await expect(issue).toContainText("BKLG-0001");
  await expect(issue).toContainText("CASE-0010");
  await expect(page.getByRole("region", { name: "Perubahan kode" })).toContainText("aaaaaaa");
  await expect(page.getByRole("button", { name: "Approve" })).toBeEnabled();
});

test("Request Changes wajib komentar dan menampilkan pesan FR-UC06-15", async ({ page }) => {
  let current = detail();
  const mock = await mockApi(page, REVIEWER, ({ method, path, body }) => {
    if (method === "GET" && path === "/pull-requests/1") return { body: { data: current } };
    if (method === "POST" && path === "/pull-requests/1/reviews") {
      current = detail({ review_status: "Changes Requested", review_state: "changes_requested", status_label: "Changes Requested", reviews: [review("Changes Requested", "synced", body.comment)] });
      return { body: { success: true, message: "Perubahan memerlukan perbaikan.", data: { review: review("Changes Requested", "synced", body.comment), pull_request: current, backlog: { backlog_id: 1, status: "Sedang Dikerjakan" } }, meta: { sync: { status: "synced" } } } };
    }
  });
  await page.goto("/pull-request/1");
  await page.getByRole("button", { name: "Request Changes" }).click();
  await page.getByRole("button", { name: "Kirim Request Changes" }).click();
  await expect(page.getByText("Komentar wajib diisi untuk Request Changes.")).toBeVisible();
  expect(mock.calls.filter(call => call.method === "POST" && call.path.endsWith("/reviews"))).toHaveLength(0);

  await page.getByLabel(/Komentar perbaikan/).fill("Tambahkan retry.");
  await page.getByRole("button", { name: "Kirim Request Changes" }).click();
  await expect(page.getByText("Perubahan memerlukan perbaikan.", { exact: true })).toBeVisible();
  await expect(page.getByText("Backlog kembali ke status Sedang Dikerjakan", { exact: false })).toBeVisible();
  await expect(page.getByRole("button", { name: "Approve" })).toHaveCount(0);
  expect(mock.calls.find(call => call.method === "POST" && call.path.endsWith("/reviews"))?.body).toEqual({ decision: "request_changes", comment: "Tambahkan retry." });
});

test("sinkron GitHub gagal = peringatan + Kirim ulang, lalu Ready for Merge", async ({ page }) => {
  let attempt = 0;
  let current = detail();
  await mockApi(page, REVIEWER, ({ method, path }) => {
    if (method === "GET" && path === "/pull-requests/1") return { body: { data: current } };
    if (method === "POST" && path === "/pull-requests/1/reviews") {
      attempt += 1;
      if (attempt === 1) {
        current = detail({ reviews: [review("Approved", "gagal")] });
        return { body: { success: true, message: "Review tersimpan tetapi belum tersinkron ke GitHub. Silakan coba kirim ulang.", data: { review: review("Approved", "gagal"), pull_request: current, backlog: { backlog_id: 1, status: "Ready for Pull Request" } }, meta: { sync: { status: "gagal", code: "GITHUB_UNAVAILABLE", retry_after_seconds: null } } } };
      }
      current = detail({ review_status: "Approved", review_state: "approved", ready_for_merge: true, status_label: "Ready for Merge", reviews: [review("Approved", "synced")] });
      return { body: { success: true, message: "Review berhasil disinkronkan. Pull Request disetujui; perubahan siap dilanjutkan ke proses merge.", data: { review: review("Approved", "synced"), pull_request: current, backlog: { backlog_id: 1, status: "Ready for Pull Request" } }, meta: { sync: { status: "synced" } } } };
    }
  });
  await page.goto("/pull-request/1");
  await page.getByRole("button", { name: "Approve" }).click();
  await expect(page.getByText("Review tersimpan tetapi belum tersinkron ke GitHub.", { exact: false })).toBeVisible();
  await expect(page.getByText("status PR tetap Open", { exact: false })).toBeVisible();
  await page.getByRole("button", { name: "Kirim ulang" }).click();
  await expect(page.getByText("Pull Request disetujui", { exact: false })).toBeVisible();
  await expect(page.getByText("Ready for Merge").first()).toBeVisible();
  expect(attempt).toBe(2);
});

test("penulis PR dan PR non-Open tidak mendapat tombol review", async ({ page }) => {
  await mockApi(page, { ...USERS.developer, user_id: 2 }, ({ method, path }) => {
    if (method === "GET" && path === "/pull-requests/1") return { body: { data: detail() } };
    if (method === "GET" && path === "/pull-requests/2") return { body: { data: detail({ pull_request_id: 2, developer: { user_id: 9, nama: "Developer Lain" }, review_status: "Merged", review_state: "approved", status_label: "Merged", lifecycle_state: "merged" }) } };
  });
  await page.goto("/pull-request/1");
  await expect(page.getByTestId("review-blocked")).toHaveText(/penulis Pull Request ini/);
  await expect(page.getByRole("button", { name: "Approve" })).toHaveCount(0);
  await page.goto("/pull-request/2");
  await expect(page.getByTestId("review-blocked")).toHaveText(/review baru tidak diterima/);
});

test("Tim Maintenance tidak dapat membuka halaman review PR", async ({ page }) => {
  await mockApi(page, USERS.maintenance);
  await page.goto("/pull-request");
  await expect(page.getByText("Akses ditolak")).toBeVisible();
  await expect(page.getByRole("link", { name: "Pull Request" })).toHaveCount(0);
  await expect(page.getByRole("link", { name: "Status & Riwayat" })).toBeVisible();
});

test("riwayat menampilkan alur Feedback → Review beserta ulasan asal", async ({ page }) => {
  await mockApi(page, USERS.maintenance, ({ method, path }) => {
    if (method === "GET" && path === "/maintenance-cases/10/history") {
      return { body: { data: {
        case: { case_id: 10, judul_issue: "Notifikasi tidak muncul", status_validasi: "Valid", severity: "High", kategori: "defect" },
        timeline: [
          { type: "case_created", occurred_at: "2026-11-10T08:00:00+00:00", actor: null, detail: {} },
          { type: "case_validated", occurred_at: "2026-11-11T09:00:00+00:00", actor: { user_id: 1, nama: "Tim Maintenance Uji", role: "Tim Maintenance" }, detail: { keputusan: "Valid" } },
          { type: "backlog_created", occurred_at: "2026-11-11T09:00:00+00:00", actor: { user_id: 1, nama: "Tim Maintenance Uji", role: "Tim Maintenance" }, detail: { backlog_id: 1 } },
          { type: "issue_claimed", occurred_at: "2026-11-12T10:00:00+00:00", actor: { user_id: 2, nama: "Developer Uji", role: "Developer" }, detail: { source_branch: "maintenance/issue-1" } },
          { type: "pull_request_submitted", occurred_at: "2026-11-14T08:00:00+00:00", actor: { user_id: 2, nama: "Developer Uji", role: "Developer" }, detail: { pull_request_id: 1, pull_request_number: 42, title: "Perbaikan notifikasi", source_branch: "maintenance/issue-1", target_branch: "main" } },
          { type: "review_submitted", occurred_at: "2026-11-14T09:00:00+00:00", actor: { user_id: 3, nama: "Reviewer Uji", role: "Developer" }, detail: { pull_request_id: 1, decision: "Approved", sync_status: "synced" } },
        ],
        pull_requests: [{ pull_request_id: 1, pull_request_number: 42, review_status: "Approved", ready_for_merge: true, lifecycle_state: "open", title: "Perbaikan notifikasi" }],
      } } };
    }
    if (method === "GET" && path === "/maintenance-cases/10") {
      return { body: { data: { case: {}, reviews: [{ review_id: 1, source_review_id: "rv-1", teks_ulasan: "Notifikasi tidak pernah masuk.", rating: 1, sumber: "Play Store", tanggal_ulasan: "2026-11-09 10:00:00" }] } } };
    }
  });
  await page.goto("/riwayat/10");
  const flow = page.getByRole("region", { name: "Alur maintenance" });
  for (const stage of ["Feedback", "Case", "Backlog", "Issue", "Pull Request", "Review"]) await expect(flow).toContainText(stage);
  await expect(flow.getByText("selesai")).toHaveCount(6);
  const timeline = page.getByRole("region", { name: "Timeline" });
  await expect(timeline).toContainText("Case dibuat");
  await expect(timeline).toContainText("Sistem (analisis otomatis)");
  await expect(timeline).toContainText("Review diberikan");
  await expect(timeline).toContainText("Tersinkron ke GitHub");
  await expect(page.getByRole("region", { name: "Pull Request" })).toContainText("Ready for Merge");
  await expect(page.getByRole("region", { name: "Feedback asal" })).toContainText("Notifikasi tidak pernah masuk.");
});
