/**
 * Minimal stateful stand-in for the Laravel API (/api/v1) used by the
 * Playwright E2E suite. Searches progress queued -> running -> completed over
 * ~3 s so the UI's polling fallback is exercised end to end.
 */
import http from "node:http";

const PORT = Number(process.env.MOCK_API_PORT || 4010);
const API_KEY = process.env.MOCK_API_KEY || "e2e-test-key";
const BASE = "/api/v1";

const searches = new Map();
const recorded = [];
let seq = 0;

const ulid = (prefix) => `${prefix}${String(++seq).padStart(26 - prefix.length, "0")}`;
const usd = (amount) => ({ amount, currency: "USD" });

const SOURCES = [
  { id: "01SRC00000000000000000001", type: "site", name: "Example Electronics", domain: "shop.example.com", country: "US", currency: "USD", render: "auto", enabled: true, is_global: false, health_status: "healthy", config: {}, forked_from_id: null, feed_refreshed_at: null, created_at: "2026-10-01T00:00:00Z", updated_at: "2026-10-01T00:00:00Z" },
  { id: "01SRC00000000000000000002", type: "site", name: "Store Net", domain: "store.example.net", country: "US", currency: "USD", render: "never", enabled: true, is_global: false, health_status: "degraded", config: {}, forked_from_id: null, feed_refreshed_at: null, created_at: "2026-10-01T00:00:00Z", updated_at: "2026-10-01T00:00:00Z" },
];

function offer(id, domain, name, price, shipping, band, confidence, extra = {}) {
  const total = (Number(price) + Number(shipping)).toFixed(2);
  return {
    id, site: { domain, name }, url: `https://${domain}/p/sony-wh-1000xm5`, title: `Sony WH-1000XM5 Headphones (${name})`,
    image_url: null, price: usd(price), list_price: usd("399.99"), shipping: usd(shipping), total: usd(total),
    converted_total: { ...usd(total), fx_rate_date: "2026-10-30" }, availability: "in_stock", condition: "new", seller: name,
    brand: "Sony", rating: null, review_count: null, variant: null,
    identifiers: { gtin: "0027242923782", mpn: "WH1000XM5/B", sku: null },
    match: { confidence, band, type: band === "exact" ? "exact_gtin" : "scored", breakdown: band === "exact" ? { gtin: 1 } : { title_similarity: confidence } },
    extraction_method: "json_ld", price_outlier: false, is_duplicate: false, observed_at: new Date().toISOString(),
    ...extra,
  };
}

const OFFERS = [
  offer("01OFF00000000000000000001", "shop.example.com", "Example Electronics", "329.99", "0.00", "exact", 1, {
    rating: 4.7, review_count: 1234, variant: { color: "Black" },
  }),
  offer("01OFF00000000000000000002", "store.example.net", "Store Net", "309.00", "9.99", "likely", 0.84, {
    match: { confidence: 0.84, band: "likely", type: "scored", breakdown: { title_similarity: 0.84, hard_negatives: ["refurbished"] } },
  }),
  offer("01OFF00000000000000000003", "deals.example.org", "Deals Org", "349.00", "0.00", "exact", 0.97, { extraction_method: "html" }),
  offer("01OFF00000000000000000004", "junk.example.io", "Junk IO", "99.00", "0.00", "rejected", 0.12, {
    title: null, brand: null, match: { confidence: 0.12, band: "rejected", type: "scored", breakdown: { reason: "no_signals" } },
  }),
  // Same Store Net listing surfaced again by the shopping engine: hidden unless include_duplicates=1.
  offer("01OFF00000000000000000005", "store.example.net", "Store Net (Shopping)", "309.00", "9.99", "likely", 0.8, {
    is_duplicate: true, extraction_method: "provider",
  }),
];

const RANK = { exact: 0, likely: 1, possible: 2, rejected: 3 };
const amount = (o) => Number(o.converted_total.amount);
const SORTS = {
  rank: (a, b) => RANK[a.match.band] - RANK[b.match.band] || Number(a.price_outlier) - Number(b.price_outlier) || amount(a) - amount(b),
  total_price: (a, b) => amount(a) - amount(b),
  price: (a, b) => Number(a.price.amount) - Number(b.price.amount),
  confidence: (a, b) => a.match.confidence - b.match.confidence,
  site: (a, b) => (a.site.name ?? a.site.domain).localeCompare(b.site.name ?? b.site.domain),
  observed_at: (a, b) => a.observed_at.localeCompare(b.observed_at),
};
const BOOL = new Set(["0", "1", "true", "false"]);

function stateOf(s) {
  if (s.cancelled) return "cancelled";
  const age = Date.now() - s.createdMs;
  if (age < 800) return "queued";
  if (age < 3000) return "running";
  return "completed";
}

function view(s) {
  const status = stateOf(s);
  const done = status === "queued" ? 0 : status === "running" ? 2 : 6;
  const visible = visibleOffers(s);
  return {
    id: s.id, status, input: s.input,
    product_query: {
      title: s.input.title ?? null, cleaned_title: s.input.title ?? null, tokens: [], brand: "Sony", model: "WH-1000XM5",
      gtin: s.input.gtin ?? null, mpn: null, image_url: null, image_phash: null,
      attributes: { color: "black" }, condition: s.input.options?.condition ?? null, labels: [], reference_url: s.input.reference_url ?? null,
    },
    options: s.input.options ?? {}, target_currency: "USD", country: "US",
    progress: { total: status === "queued" ? 0 : 6, done },
    stats: status === "completed" ? stats(visible.filter((o) => o.match.band !== "rejected" && !o.is_duplicate)) : null,
    error: null, monitor_id: null,
    sources: SOURCES.map((src, i) => ({ source_id: src.id, name: src.name, domain: src.domain, type: "site", status: status === "completed" ? "completed" : i === 0 && status === "running" ? "completed" : "running", candidates_found: 3, offers_found: status === "completed" ? 2 : i === 0 && status === "running" ? 1 : 0, error: null })),
    started_at: new Date(s.createdMs).toISOString(), completed_at: status === "completed" ? new Date(s.createdMs + 3000).toISOString() : null,
    created_at: new Date(s.createdMs).toISOString(),
    links: { self: `${BASE}/searches/${s.id}`, offers: `${BASE}/searches/${s.id}/offers` },
    realtime: { channel: `private-tenant.1.search.${s.id}` },
  };
}

function visibleOffers(s) {
  const status = stateOf(s);
  if (status === "queued") return [];
  if (status === "running") return OFFERS.slice(0, 1);
  return OFFERS;
}

function stats(list) {
  const vals = list.map((o) => Number(o.converted_total.amount)).sort((a, b) => a - b);
  if (!vals.length) return null;
  const q = (p) => { const pos = (vals.length - 1) * p; const lo = Math.floor(pos); return vals[lo] + (vals[Math.ceil(pos)] - vals[lo]) * (pos - lo); };
  const cheapest = list.reduce((a, b) => (Number(a.converted_total.amount) <= Number(b.converted_total.amount) ? a : b));
  return { currency: "USD", min: vals[0].toFixed(2), p25: q(0.25).toFixed(2), median: q(0.5).toFixed(2), p75: q(0.75).toFixed(2), max: vals.at(-1).toFixed(2), mean: (vals.reduce((a, b) => a + b, 0) / vals.length).toFixed(2), offer_count: vals.length, site_count: new Set(list.map((o) => o.site.domain)).size, cheapest_offer_id: cheapest.id };
}

function send(res, status, body, headers = {}) {
  res.writeHead(status, { "Content-Type": "application/json", ...headers });
  res.end(body === undefined ? "" : JSON.stringify(body));
}
const problem = (res, status, title, extra = {}) =>
  send(res, status, { type: "about:blank", title, status, ...extra }, { "Content-Type": "application/problem+json" });

async function readBody(req) {
  const chunks = [];
  for await (const c of req) chunks.push(c);
  const raw = Buffer.concat(chunks).toString("utf8");
  if ((req.headers["content-type"] || "").includes("application/json") && raw) return JSON.parse(raw);
  return raw;
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://localhost:${PORT}`);
  const path = url.pathname;

  // Test introspection (no auth).
  if (path === "/__mock/requests") return send(res, 200, recorded);
  if (path === "/__mock/reset") { recorded.length = 0; searches.clear(); return send(res, 204); }

  if (!path.startsWith(BASE)) return problem(res, 404, "Not found");
  if (req.headers.authorization !== `Bearer ${API_KEY}`) return problem(res, 401, "Unauthenticated");

  const body = req.method === "GET" ? undefined : await readBody(req);
  recorded.push({ method: req.method, path: path.slice(BASE.length), query: url.search, body, contentType: req.headers["content-type"] ?? null });
  const parts = path.slice(BASE.length).split("/").filter(Boolean);

  if (parts[0] === "sources" && parts.length === 1) return send(res, 200, { data: SOURCES, meta: { next_cursor: null } });
  if (parts[0] === "sources" && parts[1] === "global") return send(res, 200, { data: [], meta: { next_cursor: null } });

  if (parts[0] === "searches" && parts.length === 1) {
    if (req.method === "POST") {
      const input = typeof body === "object" ? body : {};
      if (!input.title && !input.gtin && !input.mpn && !input.image_url && !input.reference_url) {
        return problem(res, 422, "The given data was invalid.", { errors: { title: ["Provide at least one identifier."] } });
      }
      const s = { id: ulid("01SRCH"), input, createdMs: Date.now(), cancelled: false };
      searches.set(s.id, s);
      return send(res, 202, { data: view(s) });
    }
    return send(res, 200, { data: [...searches.values()].map(view).reverse(), meta: { next_cursor: null } });
  }

  if (parts[0] === "searches" && parts[1]) {
    const s = searches.get(parts[1]);
    if (!s) return problem(res, 404, "Search not found");
    const sub = parts[2];
    if (!sub) return send(res, 200, { data: view(s) });
    if (sub === "cancel") { s.cancelled = true; return send(res, 200, { data: view(s) }); }
    if (sub === "offers") {
      const q = url.searchParams;
      for (const key of ["include_duplicates", "include_outliers"]) {
        if (q.has(key) && !BOOL.has(q.get(key))) return problem(res, 422, "The given data was invalid.", { errors: { [key]: [`The ${key} field must be true or false.`] } });
      }
      const sort = q.get("sort") || "rank";
      const key = sort.replace(/^-/, "");
      if (!SORTS[key]) return problem(res, 422, "The given data was invalid.", { errors: { sort: ["The selected sort is invalid."] } });
      const bands = (q.get("band") || "exact,likely").split(",");
      const withDupes = q.get("include_duplicates") === "1" || q.get("include_duplicates") === "true";
      const noOutliers = q.get("include_outliers") === "0" || q.get("include_outliers") === "false";
      const list = visibleOffers(s).filter((o) => bands.includes(o.match.band) && (withDupes || !o.is_duplicate) && !(noOutliers && o.price_outlier));
      const dir = sort.startsWith("-") ? -1 : 1;
      list.sort((a, b) => dir * SORTS[key](a, b));
      const statList = list.filter((o) => o.match.band !== "rejected" && !o.is_duplicate);
      return send(res, 200, { data: list, meta: { next_cursor: null, stats: stateOf(s) === "completed" ? stats(statList) : null } });
    }
    if (sub === "tasks") {
      return send(res, 200, { data: [
        { id: "T1", source_id: SOURCES[0].id, source_name: SOURCES[0].name, stage: "fetch", url: "https://shop.example.com/search?q=sony", status: "succeeded", attempts: 1, http_status: 200, error_code: null, error_message: null, duration_ms: 812, created_at: new Date().toISOString() },
        { id: "T2", source_id: SOURCES[1].id, source_name: SOURCES[1].name, stage: "fetch", url: "https://store.example.net/p/1", status: "blocked", attempts: 3, http_status: 403, error_code: "blocked_waf", error_message: "Cloudflare challenge page returned 403", duration_ms: 1500, created_at: new Date().toISOString() },
      ] });
    }
    if (sub === "export") {
      res.writeHead(200, { "Content-Type": "text/csv; charset=utf-8", "Content-Disposition": `attachment; filename="search-${s.id}.csv"` });
      res.write("site,url,price,shipping,total,currency,band,confidence\n");
      for (const o of visibleOffers(s)) {
        res.write(`${o.site.domain},${o.url},${o.price.amount},${o.shipping.amount},${o.total.amount},USD,${o.match.band},${o.match.confidence}\n`);
      }
      return res.end();
    }
    if (sub === "rerun") {
      const n = { id: ulid("01SRCH"), input: s.input, createdMs: Date.now(), cancelled: false };
      searches.set(n.id, n);
      return send(res, 202, { data: view(n) });
    }
  }

  if (parts[0] === "tracked-products" && req.method === "POST") {
    // Writes accept decimals or Money objects; responses always use Money.
    const money = (v) => (v === null || v === undefined || v === "" ? null : typeof v === "object" ? v : { amount: Number(v).toFixed(2), currency: body.currency ?? "USD" });
    return send(res, 201, { data: {
      id: ulid("01PROD"), external_ref: null, brand: null, gtin: null, mpn: null, image_url: null, attributes: null, ...body,
      reference_price: money(body.reference_price), map_price: money(body.map_price), currency: body.currency ?? "USD",
      archived_at: null, created_at: new Date().toISOString(), latest_prices: [],
    } });
  }
  if (parts[0] === "monitors" && req.method === "POST") {
    return send(res, 201, { data: {
      id: ulid("01MON"), timezone: "UTC", source_ids: [], options: {}, alert_rules: {}, status: "active", ...body,
      next_run_at: null, last_run_at: null, created_at: new Date().toISOString(),
    } });
  }

  return problem(res, 404, "Not found");
});

server.listen(PORT, "127.0.0.1", () => {
  console.log(`mock PriceLens API listening on http://127.0.0.1:${PORT}${BASE}`);
});
