import { compareMoney, moneyValue } from "./money";
import type { MatchBand, Money, Offer, OfferFilters, OfferSort, Stats } from "./types";

export const DEFAULT_BANDS: MatchBand[] = ["exact", "likely"];

export const DEFAULT_SORT: OfferSort = "rank";

export const defaultOfferFilters: OfferFilters = {
  band: DEFAULT_BANDS,
  sort: DEFAULT_SORT,
};

/** The price used for ranking/comparison: converted total, else total, else price. */
export function effectiveTotal(offer: Offer): Money | null {
  return offer.converted_total ?? offer.total ?? offer.price;
}

const BAND_RANK: Record<MatchBand, number> = { exact: 0, likely: 1, possible: 2, rejected: 3 };

/** Mirrors the API's default `rank`: band, non-outliers, in stock, cheapest converted total. */
function compareRank(a: Offer, b: Offer): number {
  return (
    BAND_RANK[a.match.band] - BAND_RANK[b.match.band] ||
    Number(a.price_outlier) - Number(b.price_outlier) ||
    Number(a.availability !== "in_stock") - Number(b.availability !== "in_stock") ||
    compareMoney(effectiveTotal(a), effectiveTotal(b), "asc")
  );
}

/** Timestamps compare as strings (ISO-8601 UTC); missing ones sort last in both directions. */
function compareTime(a: string | null, b: string | null, direction: "asc" | "desc"): number {
  if (a === b) return 0;
  if (a === null) return 1;
  if (b === null) return -1;
  return direction === "asc" ? a.localeCompare(b) : b.localeCompare(a);
}

const siteName = (o: Offer) => o.site.name ?? o.site.domain;

/** Local sort mirroring the API's `sort` semantics ("-" prefix = descending). */
export function sortOffers(offers: readonly Offer[], sort: OfferSort | undefined): Offer[] {
  const out = [...offers];
  if (!sort) return out;
  const tie = (a: Offer, b: Offer) => a.id.localeCompare(b.id);
  const cmp: Record<OfferSort, (a: Offer, b: Offer) => number> = {
    rank: (a, b) => compareRank(a, b) || tie(a, b),
    total_price: (a, b) => compareMoney(effectiveTotal(a), effectiveTotal(b), "asc") || tie(a, b),
    "-total_price": (a, b) => compareMoney(effectiveTotal(a), effectiveTotal(b), "desc") || tie(a, b),
    price: (a, b) => compareMoney(a.price, b.price, "asc") || tie(a, b),
    "-price": (a, b) => compareMoney(a.price, b.price, "desc") || tie(a, b),
    confidence: (a, b) => a.match.confidence - b.match.confidence || tie(a, b),
    "-confidence": (a, b) => b.match.confidence - a.match.confidence || tie(a, b),
    site: (a, b) => siteName(a).localeCompare(siteName(b)) || tie(a, b),
    "-site": (a, b) => siteName(b).localeCompare(siteName(a)) || tie(a, b),
    observed_at: (a, b) => compareTime(a.observed_at, b.observed_at, "asc") || tie(a, b),
    "-observed_at": (a, b) => compareTime(a.observed_at, b.observed_at, "desc") || tie(a, b),
  };
  return out.sort(cmp[sort]);
}

/** Client-side check used when inserting streamed offers into a filtered list. */
export function offerMatchesFilters(offer: Offer, f: OfferFilters): boolean {
  if (f.band && f.band.length > 0 && !f.band.includes(offer.match.band)) return false;
  if (offer.is_duplicate && !f.include_duplicates) return false;
  if (offer.price_outlier && f.include_outliers === false) return false;
  if (f.availability && offer.availability !== f.availability) return false;
  if (f.condition && offer.condition !== f.condition) return false;
  if (f.site && offer.site.domain !== f.site) return false;
  const total = moneyValue(effectiveTotal(offer));
  const min = f.min_price ? Number(f.min_price) : null;
  const max = f.max_price ? Number(f.max_price) : null;
  if (min !== null && (total === null || total < min)) return false;
  if (max !== null && (total === null || total > max)) return false;
  return true;
}

/**
 * Serialize filters as query params for GET /searches/{id}/offers. `band` is a
 * comma-separated string; booleans use "1"/"0" (Laravel's `boolean` rule
 * rejects "true"/"false"). Defaults are omitted so the API applies its own.
 */
export function filtersToQuery(f: OfferFilters): Record<string, string | undefined> {
  return {
    band: f.band && f.band.length > 0 ? f.band.join(",") : undefined,
    availability: f.availability || undefined,
    condition: f.condition || undefined,
    site: f.site || undefined,
    min_price: f.min_price || undefined,
    max_price: f.max_price || undefined,
    sort: f.sort,
    include_duplicates: f.include_duplicates ? "1" : undefined,
    include_outliers: f.include_outliers === false ? "0" : undefined,
  };
}

function quantile(sorted: number[], q: number): number {
  if (sorted.length === 1) return sorted[0];
  const pos = (sorted.length - 1) * q;
  const lo = Math.floor(pos);
  const hi = Math.ceil(pos);
  return sorted[lo] + (sorted[hi] - sorted[lo]) * (pos - lo);
}

/**
 * Provisional stats computed from the offers loaded so far (used while a
 * search is still running and the server hasn't produced final stats).
 */
export function computeStats(offers: readonly Offer[], currency: string | null): Stats | null {
  const priced = offers
    .filter((o) => o.match.band !== "rejected" && !o.is_duplicate)
    .map((o) => ({ o, money: effectiveTotal(o) }))
    .filter((x): x is { o: Offer; money: Money } => x.money !== null && moneyValue(x.money) !== null)
    .filter((x) => !currency || x.money.currency === currency);
  if (priced.length === 0) return null;
  const values = priced.map((x) => moneyValue(x.money)!).sort((a, b) => a - b);
  const cheapest = priced.reduce((best, x) => (moneyValue(x.money)! < moneyValue(best.money)! ? x : best));
  const fmt = (n: number) => n.toFixed(2);
  return {
    currency: currency ?? priced[0].money.currency,
    min: fmt(values[0]),
    p25: fmt(quantile(values, 0.25)),
    median: fmt(quantile(values, 0.5)),
    p75: fmt(quantile(values, 0.75)),
    max: fmt(values[values.length - 1]),
    mean: fmt(values.reduce((s, v) => s + v, 0) / values.length),
    offer_count: priced.length,
    site_count: new Set(priced.map((x) => x.o.site.domain)).size,
    cheapest_offer_id: cheapest.o.id,
  };
}

/** Display title for an offer (`title` may be null). */
export function offerTitle(offer: Pick<Offer, "title" | "site">): string {
  return offer.title?.trim() || `Listing on ${offer.site.name ?? offer.site.domain}`;
}

export const BAND_LABEL: Record<MatchBand, string> = {
  exact: "Exact",
  likely: "Likely",
  possible: "Possible",
  rejected: "Rejected",
};
