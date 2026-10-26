import { test, expect, type Page } from "@playwright/test";

const password = process.env.MAINTAINGOV_DEMO_PASSWORD || "password123";
const alerts = (page: Page) => page.locator('p[role="alert"]');

async function login(page: Page, role = "maintenance") {
  await page.goto("/login");
  await page.getByLabel("Email dinas", { exact: true }).fill(role + "@maintaingov.test");
  await page.getByLabel("Kata sandi", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(page.getByRole("heading", { name: role === "maintenance" ? "Analisis Feedback" : "Maintenance Backlog", exact: true })).toBeVisible();
}

function csv(rows = 3) {
  return "reviewId,Review Teks,rating,date\n" + Array.from({ length: rows }, (_, i) =>
    `browser-${i + 1},Tidak bisa login setelah update,1,2026-10-26 08:00:00`).join("\n");
}

async function selectCsv(page: Page, rows = 3) {
  await page.getByLabel("Dataset feedback CSV").setInputFiles({ name: "browser.csv", mimeType: "text/csv", buffer: Buffer.from(csv(rows)) });
}

async function upload(page: Page, rows = 3) {
  await selectCsv(page, rows);
  await page.getByRole("button", { name: "Analisis feedback", exact: true }).click();
  await expect(page.getByRole("heading", { name: /Batch #.*Selesai/ })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Hasil per ulasan" })).toBeVisible();
}

test("login validates fields, rejects invalid credentials, supports both roles and logout", async ({ page }) => {
  await page.goto("/login");
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  expect(await page.getByLabel("Email dinas", { exact: true }).evaluate((el: HTMLInputElement) => el.validity.valueMissing)).toBe(true);
  await page.getByLabel("Email dinas", { exact: true }).fill("maintenance@maintaingov.test");
  await page.getByLabel("Kata sandi", { exact: true }).fill("wrong-password");
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(alerts(page)).toBeVisible();
  await login(page);
  await page.getByRole("button", { name: "Keluar", exact: true }).click();
  await expect(page).toHaveURL(/\/login$/);
  await page.goto("/analisis-feedback");
  await expect(page).toHaveURL(/\/login$/);
  await login(page, "developer");
  await page.getByRole("button", { name: "Keluar", exact: true }).click();
  await expect(page).toHaveURL(/\/login$/);
});

test("CSV replacement, pagination beyond 200, source reviews, refresh and expired session", async ({ page }) => {
  await login(page);
  const input = page.getByLabel("Dataset feedback CSV");
  const submit = page.getByRole("button", { name: "Analisis feedback", exact: true });
  await input.setInputFiles({ name: "wrong.txt", mimeType: "text/plain", buffer: Buffer.from("x") });
  await expect(alerts(page)).toContainText("Pilih file CSV.");
  await expect(submit).toBeDisabled();
  await input.setInputFiles({ name: "big.csv", mimeType: "text/csv", buffer: Buffer.alloc(10_000_001) });
  await expect(alerts(page)).toContainText("10.000.000 byte");
  await expect(submit).toBeDisabled();
  await input.setInputFiles({ name: "invalid.csv", mimeType: "text/csv", buffer: Buffer.from("wrong,columns\nx,y\n") });
  await submit.click();
  await expect(alerts(page)).toBeVisible();
  await expect(submit).toBeDisabled();
  await upload(page, 201);
  const reviews = page.locator('section[aria-labelledby="review-heading"]');
  for (let i = 0; i < 8; i++) {
    await expect(reviews.getByText(`Halaman ${i + 1} dari 9 · 201 data`, { exact: true })).toBeVisible();
    await reviews.getByRole("button", { name: "Berikutnya", exact: true }).click();
  }
  await expect(reviews.getByRole("cell", { name: "browser-201", exact: true })).toBeVisible();
  await expect(reviews.getByRole("button", { name: "Berikutnya", exact: true })).toBeDisabled();
  const item = page.locator("article").first();
  await item.getByRole("button", { name: "Lihat ulasan pembentuk case", exact: true }).click();
  await expect(item.getByText("Halaman 1 dari 9 · 201 data", { exact: true })).toBeVisible();
  await item.getByRole("button", { name: "Berikutnya", exact: true }).click();
  await expect(item.getByText("Halaman 2 dari 9 · 201 data", { exact: true })).toBeVisible();
  const url = page.url();
  await page.reload();
  await expect(page.getByRole("heading", { name: /Batch #.*Selesai/ })).toBeVisible();
  expect(page.url()).toBe(url);
  await page.route("**/api/v1/analysis-batches/*/reviews?**", route => route.fulfill({ status: 401, contentType: "application/json", body: '{"message":"Unauthenticated."}' }));
  await page.locator('section[aria-labelledby="review-heading"]').getByRole("button", { name: "Berikutnya", exact: true }).click();
  await expect(page).toHaveURL(/\/login$/);
});

test("transient status error recovers automatically", async ({ page }) => {
  await login(page);
  let failed = false;
  await page.route("**/api/v1/analysis-batches/*/status", async route => {
    if (!failed) { failed = true; await route.fulfill({ status: 503, contentType: "application/json", body: '{"message":"Gangguan status sementara"}' }); }
    else await route.continue();
  });
  await upload(page);
  expect(failed).toBe(true);
  await expect(alerts(page)).toHaveCount(0);
});

test("manual status refresh resumes a paused poll", async ({ page }) => {
  await login(page);
  await page.route("**/api/v1/analysis-batches/*/status", route => route.fulfill({ status: 400, contentType: "application/json", body: '{"message":"Status perlu dimuat ulang"}' }));
  await selectCsv(page);
  await page.getByRole("button", { name: "Analisis feedback", exact: true }).click();
  await expect(alerts(page)).toContainText("Pembaruan otomatis dijeda.");
  await page.unroute("**/api/v1/analysis-batches/*/status");
  await page.getByRole("button", { name: "Cek status sekarang", exact: true }).click();
  await expect(page.getByRole("heading", { name: /Batch #.*Selesai/ })).toBeVisible();
});

test("mobile viewport can upload and read results", async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await login(page);
  await upload(page);
  await expect(page.getByRole("status").filter({ hasText: "Feedback berhasil diproses" })).toBeVisible();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  expect(overflow).toBe(false);
});
