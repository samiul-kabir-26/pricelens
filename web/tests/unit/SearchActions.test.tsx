import { screen, waitFor } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { monitorOptionsFromSearch, trackedProductFromSearch } from "@/components/results/SearchActions";
import { SearchHeader, searchDisplayTitle } from "@/components/results/SearchHeader";
import type { Stats } from "@/lib/types";
import { makeSearch } from "../fixtures";
import { json, mockFetch, renderWithClient } from "../utils";

const stats: Stats = {
  currency: "EUR",
  min: "300.00",
  p25: "310.00",
  median: "319.50",
  p75: "330.00",
  max: "350.00",
  mean: "321.00",
  offer_count: 4,
  site_count: 3,
  cheapest_offer_id: null,
};

describe("trackedProductFromSearch", () => {
  it("sends the median as a plain decimal with its currency", () => {
    const body = trackedProductFromSearch(makeSearch({ target_currency: "USD" }), stats);
    expect(body).toMatchObject({ reference_price: "319.50", currency: "EUR", gtin: "0027242923782", brand: "Sony" });
    expect(typeof body.reference_price).toBe("string");
  });

  it("falls back to the search currency without stats", () => {
    expect(trackedProductFromSearch(makeSearch({ target_currency: "GBP" }), null)).toMatchObject({
      reference_price: null,
      currency: "GBP",
    });
  });
});

describe("monitorOptionsFromSearch", () => {
  it("carries over search options and non-adapter sources, without the callback", () => {
    const search = makeSearch({
      options: { condition: "new", max_results: 25, country: "US", callback_url: "https://hooks.example/x" },
      input: {
        title: "Sony",
        sources: { domains: ["store.example.net"], search_engines: [{ provider: "serpapi", mode: "shopping", verify: true }], reverse_image: false },
      },
    });
    const options = monitorOptionsFromSearch(search);
    expect(options).toEqual({
      condition: "new",
      max_results: 25,
      country: "US",
      sources: { domains: ["store.example.net"], search_engines: [{ provider: "serpapi", mode: "shopping", verify: true }] },
    });
  });
});

describe("SearchHeader", () => {
  it("titles uploaded-image searches via the perceptual hash", () => {
    const search = makeSearch({ input: {}, product_query: { ...makeSearch().product_query!, title: null, cleaned_title: null, image_phash: "f0e1d2c3b4a59687" } });
    expect(searchDisplayTitle(search)).toBe("Image search");
  });

  it("links to the tracked product holding the monitor that ran the search", async () => {
    mockFetch((url) =>
      url === "/api/monitors/01MON" ? json({ data: { id: "01MON", tracked_product_id: "01PROD" } }) : undefined,
    );
    renderWithClient(
      <SearchHeader search={makeSearch({ monitor_id: "01MON" })} transport="idle" onCancel={() => {}} cancelling={false} />,
    );
    const link = await screen.findByRole("link", { name: /monitor 01MON/ });
    expect(link).toHaveAttribute("href", "/products/01PROD#monitor-01MON");
  });

  it("omits the monitor note for ad-hoc searches", async () => {
    const fetch = mockFetch(() => undefined);
    renderWithClient(<SearchHeader search={makeSearch()} transport="idle" onCancel={() => {}} cancelling={false} />);
    await waitFor(() => expect(screen.getByRole("heading", { level: 1 })).toBeInTheDocument());
    expect(screen.queryByTestId("search-monitor")).toBeNull();
    expect(fetch.calls()).toHaveLength(0);
  });
});
