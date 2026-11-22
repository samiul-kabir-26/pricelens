import { readFile } from "node:fs/promises";
import { expect, test } from "@playwright/test";

const MOCK = `http://127.0.0.1:${process.env.MOCK_API_PORT || 4010}`;

interface Recorded {
  method: string;
  path: string;
  query: string;
  body: unknown;
  contentType: string | null;
}

test.beforeEach(async ({ request }) => {
  await request.get(`${MOCK}/__mock/reset`);
});

test("search → live results → export CSV", async ({ page, request }) => {
  await page.goto("/");
  await expect(page.getByRole("heading", { name: "Find the best price" })).toBeVisible();

  await page.getByLabel("Title").fill("Sony WH-1000XM5 Wireless Headphones");
  await page.getByLabel("GTIN / EAN / UPC").fill("0027242923782");
  await page.getByLabel("Example Electronics").check();
  await page.getByRole("button", { name: "Search prices" }).click();

  await expect(page).toHaveURL(/\/searches\/01SRCH\w+$/);
  await expect(page.getByRole("heading", { level: 1, name: /Sony WH-1000XM5/ })).toBeVisible();

  // Progress is announced politely while the search runs (polling fallback).
  await expect(page.locator("[aria-live=polite]").filter({ hasText: /tasks|queued|Planning/ }).first()).toBeVisible();

  // Completed with final stats and three non-rejected offers.
  await expect(page.getByTestId("search-status")).toContainText("Completed", { timeout: 15_000 });
  const rows = page.getByTestId("offer-row");
  await expect(rows).toHaveCount(3);
  // Default `rank` order: exact matches first, cheapest of those on top.
  await expect(rows.first()).toContainText("Example Electronics");
  await expect(rows.first()).toContainText("★ 4.7 (1,234)");
  await expect(rows.first().getByRole("list", { name: "Variant" })).toContainText("Black");
  await expect(page.getByRole("region", { name: "Price summary" })).toContainText("$318.99");

  // Sort by total via the column header: ascending, then descending.
  await page.getByRole("button", { name: /^Total/ }).click();
  await expect(rows.first()).toContainText("Store Net"); // 318.99 total is lowest
  await page.getByRole("button", { name: /^Total/ }).click();
  await expect(rows.first()).toContainText("Deals Org");

  // Duplicates are hidden until requested; the toggle refetches with include_duplicates=1.
  await page.getByRole("checkbox", { name: "Show duplicates" }).check();
  await expect(rows).toHaveCount(4);
  await expect(rows.filter({ hasText: "Duplicate" })).toContainText("Store Net (Shopping)");
  await page.getByRole("checkbox", { name: "Show duplicates" }).uncheck();
  await expect(rows).toHaveCount(3);

  // Outbound links are safe.
  const link = rows.first().getByRole("link", { name: /View/ });
  await expect(link).toHaveAttribute("rel", "noopener noreferrer nofollow");
  await expect(link).toHaveAttribute("target", "_blank");

  // Export streams through the BFF.
  const downloadPromise = page.waitForEvent("download");
  await page.getByRole("link", { name: "Export CSV" }).click();
  const download = await downloadPromise;
  const csv = await readFile((await download.path())!, "utf8");
  expect(csv.split("\n")[0]).toBe("site,url,price,shipping,total,currency,band,confidence");
  expect(csv).toContain("shop.example.com");

  // The browser never sent the API key; the BFF did.
  const recorded = (await (await request.get(`${MOCK}/__mock/requests`)).json()) as Recorded[];
  const create = recorded.find((r) => r.method === "POST" && r.path === "/searches")!;
  expect(create.body).toMatchObject({
    title: "Sony WH-1000XM5 Wireless Headphones",
    gtin: "0027242923782",
    sources: { source_ids: ["01SRC00000000000000000001"] },
  });
  expect(recorded.some((r) => r.path.endsWith("/export") && r.query === "?format=csv")).toBe(true);
  const offerQueries = recorded.filter((r) => r.path.endsWith("/offers")).map((r) => new URLSearchParams(r.query));
  expect(offerQueries[0].get("sort")).toBe("rank");
  expect(offerQueries[0].get("band")).toBe("exact,likely");
  expect(offerQueries.some((q) => q.get("include_duplicates") === "1")).toBe(true);
});

test("diagnostics drawer and track this product", async ({ page, request }) => {
  await page.goto("/");
  await page.getByLabel("GTIN / EAN / UPC").fill("0027242923782");
  await page.getByRole("button", { name: "Search prices" }).click();
  await expect(page.getByTestId("search-status")).toContainText("Completed", { timeout: 15_000 });

  await page.getByRole("button", { name: "Diagnostics" }).click();
  const drawer = page.getByRole("dialog", { name: "Diagnostics" });
  await expect(drawer).toContainText("blocked_waf");
  await expect(drawer).toContainText("403");
  await expect(drawer).toContainText("Cloudflare challenge page returned 403");
  await page.keyboard.press("Escape");
  await expect(drawer).toBeHidden();

  await page.getByRole("button", { name: "Track this product" }).click();
  await expect(page.getByText("Tracking started")).toBeVisible();

  const recorded = (await (await request.get(`${MOCK}/__mock/requests`)).json()) as Recorded[];
  const product = recorded.find((r) => r.method === "POST" && r.path === "/tracked-products")!;
  // Reference price goes out as a plain decimal plus currency, not a Money object.
  expect(product.body).toMatchObject({ reference_price: "329.99", currency: "USD", gtin: "0027242923782" });
  const monitor = recorded.find((r) => r.method === "POST" && r.path === "/monitors")!;
  expect(monitor.body).toMatchObject({
    schedule: "0 */6 * * *",
    source_ids: ["01SRC00000000000000000001", "01SRC00000000000000000002"],
  });
});

test("client-side validation blocks empty and bad-GTIN searches", async ({ page, request }) => {
  await page.goto("/");
  await page.getByRole("button", { name: "Search prices" }).click();
  await expect(page.getByText(/Provide at least one of: title/)).toBeVisible();

  await page.getByLabel("GTIN / EAN / UPC").fill("0027242923781");
  await page.getByRole("button", { name: "Search prices" }).click();
  await expect(page.getByText(/check digit does not match/)).toBeVisible();

  const recorded = (await (await request.get(`${MOCK}/__mock/requests`)).json()) as Recorded[];
  expect(recorded.filter((r) => r.method === "POST" && r.path === "/searches")).toHaveLength(0);
});
