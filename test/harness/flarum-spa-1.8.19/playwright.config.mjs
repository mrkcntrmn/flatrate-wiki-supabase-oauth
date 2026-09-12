import { defineConfig } from "@playwright/test";

const baseURL = process.env.FLARUM_BASE_URL || "http://127.0.0.1:8080";

export default defineConfig({
  testDir: "./tests",
  timeout: 60_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  use: {
    baseURL,
    trace: "off",
    screenshot: "off",
    video: "off",
  },
  reporter: [["list"]],
});
