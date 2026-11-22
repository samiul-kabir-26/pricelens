import { defineConfig, devices } from "@playwright/test";

const MOCK_PORT = Number(process.env.MOCK_API_PORT || 4010);
const WEB_PORT = Number(process.env.E2E_WEB_PORT || 3100);
const API_KEY = "e2e-test-key";

/**
 * E2E runs the real Next.js app (BFF included) against e2e/mock-api.mjs, a
 * small stand-in for Laravel. Reverb is left unconfigured so the UI uses its
 * 2 s polling fallback.
 */
export default defineConfig({
  testDir: "./e2e",
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? "github" : "list",
  use: {
    baseURL: `http://127.0.0.1:${WEB_PORT}`,
    trace: "retain-on-failure",
    acceptDownloads: true,
  },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"], viewport: { width: 1440, height: 900 } } }],
  webServer: [
    {
      command: "node e2e/mock-api.mjs",
      url: `http://127.0.0.1:${MOCK_PORT}/__mock/requests`,
      env: { MOCK_API_PORT: String(MOCK_PORT), MOCK_API_KEY: API_KEY },
      reuseExistingServer: !process.env.CI,
    },
    {
      // Production build: closest to what ships, and no dev HMR socket.
      command: process.env.E2E_WEB_COMMAND || `npx next build && npx next start --port ${WEB_PORT}`,
      url: `http://127.0.0.1:${WEB_PORT}`,
      timeout: 300_000,
      env: {
        PRICELENS_API_URL: `http://127.0.0.1:${MOCK_PORT}/api/v1`,
        PRICELENS_API_KEY: API_KEY,
        PORT: String(WEB_PORT),
        NEXT_PUBLIC_REVERB_KEY: "",
        NEXT_PUBLIC_REVERB_HOST: "",
      },
      reuseExistingServer: !process.env.CI,
    },
  ],
});
