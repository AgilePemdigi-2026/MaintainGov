import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "./tests/e2e",
  timeout: 90_000,
  expect: { timeout: 30_000 },
  workers: 1,
  retries: 0,
  reporter: [["list"], ["json", { outputFile: "test-results/results.json" }]],
  use: {
    baseURL: process.env.MAINTAINGOV_WEB_URL || "http://127.0.0.1:3000",
    viewport: { width: 1280, height: 900 },
    screenshot: "only-on-failure",
    trace: "retain-on-failure",
    launchOptions: process.env.CHROMIUM_EXECUTABLE_PATH ? {
      executablePath: process.env.CHROMIUM_EXECUTABLE_PATH,
      args: ["--no-sandbox", "--disable-dev-shm-usage", "--single-process", "--no-zygote", "--use-gl=angle", "--use-angle=swiftshader", "--enable-unsafe-swiftshader"],
    } : undefined,
  },
});
