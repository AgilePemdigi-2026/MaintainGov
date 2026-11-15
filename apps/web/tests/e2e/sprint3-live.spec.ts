/**
 * Smoke browser UC06 + Status/Riwayat terhadap Laravel NYATA (tanpa page.route ke /api/v1).
 * Opt-in: MAINTAINGOV_SPRINT3_LIVE=1, MAINTAINGOV_E2E_PASSWORD, dan data yang sudah disiapkan:
 *   MAINTAINGOV_E2E_PR_ID (PR berstatus non-Open, mis. Ready for Merge) dan MAINTAINGOV_E2E_CASE_ID.
 * Akun reviewer = Developer selain penulis PR (MAINTAINGOV_E2E_REVIEWER_EMAIL).
 * Tidak menulis data; screenshot disimpan ke MAINTAINGOV_E2E_SHOT_DIR bila diset.
 */
import { test, expect, type Page } from "@playwright/test";
import path from "node:path";

test.skip(process.env.MAINTAINGOV_SPRINT3_LIVE !== "1", "Opt in with MAINTAINGOV_SPRINT3_LIVE=1 on an isolated real stack");

const password = process.env.MAINTAINGOV_E2E_PASSWORD ?? "";
const prId = Number(process.env.MAINTAINGOV_E2E_PR_ID ?? 0);
const caseId = Number(process.env.MAINTAINGOV_E2E_CASE_ID ?? 0);
const shotDir = process.env.MAINTAINGOV_E2E_SHOT_DIR;

async function login(page: Page, email: string) {
  if (!password) throw new Error("Set MAINTAINGOV_E2E_PASSWORD; never commit the password");
  await page.goto("/login");
  await page.getByLabel("Email dinas", { exact: true }).fill(email);
  await page.getByLabel("Kata sandi", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(page).not.toHaveURL(/\/login$/, { timeout: 30000 });
}

async function shot(page: Page, name: string) {
  if (shotDir) await page.screenshot({ path: path.join(shotDir, `${name}.png`), fullPage: true });
}

test("REAL UC06: reviewer melihat daftar, detail, dan status review tersinkron", async ({ page }) => {
  await login(page, process.env.MAINTAINGOV_E2E_REVIEWER_EMAIL || "reviewer@maintaingov.test");
  await page.goto("/pull-request");
  await page.getByLabel("Filter status review").selectOption("");
  await expect(page.getByRole("table")).toBeVisible();
  await shot(page, "01-pull-request-list");

  await page.goto(`/pull-request/${prId}`);
  await expect(page.getByRole("region", { name: "Informasi Pull Request" })).toBeVisible();
  await expect(page.getByRole("region", { name: "Issue terkait" })).toContainText("BKLG-");
  await expect(page.getByRole("region", { name: "Riwayat review" })).toContainText("Tersinkron ke GitHub");
  await page.reload();
  await expect(page.getByRole("region", { name: "Riwayat review" })).toContainText("Tersinkron ke GitHub");
  await expect(page.getByRole("region", { name: "Perubahan kode" })).not.toContainText("Memuat daftar berkas");
  await shot(page, "02-pull-request-detail");

  await page.goto(`/riwayat/${caseId}`);
  await expect(page.getByRole("region", { name: "Timeline" })).toContainText("Review diberikan");
  await shot(page, "03-riwayat-developer");

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`/pull-request/${prId}`);
  await expect(page.getByRole("region", { name: "Keputusan review" })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  await shot(page, "05-mobile-pull-request-detail");
  await page.goto(`/riwayat/${caseId}`);
  await expect(page.getByRole("region", { name: "Alur maintenance" })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  await shot(page, "06-mobile-riwayat");
});

test("REAL Status/Riwayat: Tim Maintenance melihat timeline dan feedback asal", async ({ page }) => {
  await login(page, process.env.MAINTAINGOV_E2E_MAINTENANCE_EMAIL || "maintenance@maintaingov.test");
  await page.goto("/riwayat");
  await expect(page.getByRole("region", { name: "Status maintenance per backlog" })).toContainText("BKLG-");
  await shot(page, "07-status-maintenance");
  await page.goto(`/riwayat/${caseId}`);
  await expect(page.getByRole("region", { name: "Feedback asal" })).not.toContainText("hanya dapat dibuka");
  await expect(page.getByRole("region", { name: "Timeline" })).toContainText("Case divalidasi");
  await shot(page, "04-riwayat-maintenance");
  await page.goto("/pull-request");
  await expect(page.getByText("Akses ditolak")).toBeVisible();
});
