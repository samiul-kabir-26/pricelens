import { QueryClient, type InfiniteData } from "@tanstack/react-query";
import { describe, expect, it } from "vitest";
import { insertStreamedOffer } from "@/lib/hooks/useSearchLive";
import { computeStats, defaultOfferFilters, filtersToQuery, offerMatchesFilters, offerTitle, sortOffers } from "@/lib/offers";
import { qk } from "@/lib/query-keys";
import type { OffersResponse } from "@/lib/types";
import { makeOffer } from "../fixtures";

const usd = (amount: string) => ({ amount, currency: "USD" });

describe("offer filters", () => {
  const offer = makeOffer({ id: "A", total: usd("300.00"), converted_total: null });

  it("matches band, availability, site and price range", () => {
    expect(offerMatchesFilters(offer, { band: ["exact", "likely"] })).toBe(true);
    expect(offerMatchesFilters(offer, { band: ["possible"] })).toBe(false);
    expect(offerMatchesFilters(offer, { availability: "out_of_stock" })).toBe(false);
    expect(offerMatchesFilters(offer, { site: "other.example" })).toBe(false);
    expect(offerMatchesFilters(offer, { min_price: "250", max_price: "300" })).toBe(true);
    expect(offerMatchesFilters(offer, { min_price: "301" })).toBe(false);
  });

  it("hides duplicates unless included, and outliers only when explicitly excluded", () => {
    const dup = makeOffer({ id: "D", is_duplicate: true });
    const outlier = makeOffer({ id: "O", price_outlier: true });
    expect(offerMatchesFilters(dup, {})).toBe(false);
    expect(offerMatchesFilters(dup, { include_duplicates: true })).toBe(true);
    expect(offerMatchesFilters(outlier, {})).toBe(true);
    expect(offerMatchesFilters(outlier, { include_outliers: false })).toBe(false);
  });

  it("serializes to API query params", () => {
    expect(filtersToQuery({ band: ["exact", "likely"], sort: "-confidence", site: "" })).toStrictEqual({
      band: "exact,likely",
      availability: undefined,
      condition: undefined,
      site: undefined,
      min_price: undefined,
      max_price: undefined,
      sort: "-confidence",
      include_duplicates: undefined,
      include_outliers: undefined,
    });
  });

  it("serializes include toggles as Laravel booleans and omits API defaults", () => {
    expect(filtersToQuery({ include_duplicates: true, include_outliers: false })).toMatchObject({
      include_duplicates: "1",
      include_outliers: "0",
    });
    expect(filtersToQuery({ include_duplicates: false, include_outliers: true })).toMatchObject({
      include_duplicates: undefined,
      include_outliers: undefined,
    });
  });

  it("defaults to the API's rank sort", () => {
    expect(defaultOfferFilters.sort).toBe("rank");
  });
});

describe("sortOffers", () => {
  const offers = [
    makeOffer({ id: "likely-cheap", total: usd("100.00"), converted_total: null, match: { confidence: 0.7, band: "likely", type: "scored", breakdown: null } }),
    makeOffer({ id: "exact-oos", total: usd("150.00"), converted_total: null, availability: "out_of_stock" }),
    makeOffer({ id: "exact-outlier", total: usd("50.00"), converted_total: null, price_outlier: true }),
    makeOffer({ id: "exact-instock", total: usd("200.00"), converted_total: null, observed_at: null }),
  ];
  const ids = (sort: Parameters<typeof sortOffers>[1]) => sortOffers(offers, sort).map((o) => o.id);

  it("ranks by band, then non-outliers, then in stock, then cheapest", () => {
    expect(ids("rank")).toEqual(["exact-instock", "exact-oos", "exact-outlier", "likely-cheap"]);
  });

  it("supports descending price and site sorts", () => {
    const priced = ["20.00", "10.00", "30.00"].map((a, i) => makeOffer({ id: `P${i}`, price: usd(a) }));
    expect(sortOffers(priced, "price").map((o) => o.id)).toEqual(["P1", "P0", "P2"]);
    expect(sortOffers(priced, "-price").map((o) => o.id)).toEqual(["P2", "P0", "P1"]);
    const sites = sortOffers(
      ["b", "a", "c"].map((d) => makeOffer({ id: d, site: { domain: `${d}.example`, name: null } })),
      "-site",
    ).map((o) => o.site.domain);
    expect(sites).toEqual(["c.example", "b.example", "a.example"]);
  });

  it("puts offers without observed_at last in both directions", () => {
    expect(ids("observed_at").at(-1)).toBe("exact-instock");
    expect(ids("-observed_at").at(-1)).toBe("exact-instock");
  });
});

describe("offerTitle", () => {
  it("falls back to the site when the title is missing", () => {
    expect(offerTitle(makeOffer({ id: "T", title: null }))).toBe("Listing on Example Electronics");
    expect(offerTitle(makeOffer({ id: "T", title: "  " , site: { domain: "x.example", name: null } }))).toBe("Listing on x.example");
  });
});

describe("computeStats", () => {
  it("computes provisional quartiles and cheapest offer", () => {
    const offers = ["100.00", "200.00", "300.00", "400.00", "500.00"].map((a, i) =>
      makeOffer({ id: `O${i}`, total: usd(a), converted_total: null, site: { domain: `s${i % 2}.example`, name: null } }),
    );
    const stats = computeStats(offers, "USD")!;
    expect(stats).toMatchObject({ min: "100.00", p25: "200.00", median: "300.00", p75: "400.00", max: "500.00", offer_count: 5, site_count: 2, cheapest_offer_id: "O0" });
  });

  it("ignores duplicates", () => {
    const offers = [makeOffer({ id: "A", total: usd("100.00"), converted_total: null }), makeOffer({ id: "B", total: usd("10.00"), converted_total: null, is_duplicate: true })];
    expect(computeStats(offers, "USD")).toMatchObject({ min: "100.00", offer_count: 1, mean: "100.00" });
  });

  it("returns null without priced offers", () => {
    expect(computeStats([], "USD")).toBeNull();
  });
});

describe("insertStreamedOffer", () => {
  it("inserts into matching cached lists only, without duplicates", () => {
    const qc = new QueryClient();
    const page = (ids: string[]): InfiniteData<OffersResponse> => ({
      pages: [{ data: ids.map((id) => makeOffer({ id })), meta: { next_cursor: null, stats: null } }],
      pageParams: [undefined],
    });
    qc.setQueryData(qk.offers("S1", { band: ["exact"] }), page(["A"]));
    qc.setQueryData(qk.offers("S1", { band: ["possible"] }), page([]));
    qc.setQueryData(qk.offers("S2", { band: ["exact"] }), page([]));

    const streamed = makeOffer({ id: "B" });
    insertStreamedOffer(qc, "S1", streamed);
    insertStreamedOffer(qc, "S1", streamed);

    const ids = (key: readonly unknown[]) =>
      qc.getQueryData<InfiniteData<OffersResponse>>(key)!.pages.flatMap((p) => p.data.map((o) => o.id));
    expect(ids(qk.offers("S1", { band: ["exact"] }))).toEqual(["A", "B"]);
    expect(ids(qk.offers("S1", { band: ["possible"] }))).toEqual([]);
    expect(ids(qk.offers("S2", { band: ["exact"] }))).toEqual([]);
  });
});
