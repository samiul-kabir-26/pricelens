// PriceLens load test (spec §22 "Load", §23 "Performance Targets").
//
// Each virtual user creates a search against DOMAINS_PER_SEARCH fixture-farm domains, polls it until it reaches a
// terminal status, then reads its offers, and starts over. 200 VUs ≈ 200 concurrent searches.
// This script provisions nothing: the tenant, its API key and its limits must exist already (see load/README.md).
//
// Env:
//   API_URL              base URL incl. /api/v1             (default http://nginx/api/v1)
//   API_KEY              tenant API key, pl_live_...        (required)
//   SITES                comma list of fixture hostnames    (default shop-01..shop-10.fixture.test)
//   CATALOG_URL          fixture catalogue JSON             (default http://<first site>/catalog.json)
//   DOMAINS_PER_SEARCH   domains per search                 (default 5)
//   MAX_VUS              peak concurrent VUs                (default 200)
//   RAMP_UP / HOLD / RAMP_DOWN  stage durations             (default 2m / 5m / 1m)
//   POLL_INTERVAL_S      status poll interval, seconds      (default 1)
//   SEARCH_TIMEOUT_S     give up polling after              (default 120)
//   FRESH                "true" bypasses the snapshot cache (default false)

import http from 'k6/http';
import { check, fail, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import exec from 'k6/execution';

const API_URL = (__ENV.API_URL || 'http://nginx/api/v1').replace(/\/+$/, '');
const API_KEY = __ENV.API_KEY || '';
const SITES = (__ENV.SITES ||
  Array.from({ length: 10 }, (_, i) => `shop-${String(i + 1).padStart(2, '0')}.fixture.test`).join(','))
  .split(',').map((s) => s.trim()).filter(Boolean);
const CATALOG_URL = __ENV.CATALOG_URL || `http://${SITES[0]}/catalog.json`;
const DOMAINS_PER_SEARCH = Math.min(Number(__ENV.DOMAINS_PER_SEARCH || 5), SITES.length);
const MAX_VUS = Number(__ENV.MAX_VUS || 200);
const POLL_INTERVAL_S = Number(__ENV.POLL_INTERVAL_S || 1);
const SEARCH_TIMEOUT_S = Number(__ENV.SEARCH_TIMEOUT_S || 120);
const FRESH = (__ENV.FRESH || 'false') === 'true';
const TERMINAL = ['completed', 'partially_completed', 'failed', 'cancelled'];

// Custom metrics.
const createLatency = new Trend('search_create_latency', true); // POST /searches round trip
const completionTime = new Trend('search_completion_time', true); // 202 -> terminal status (wall clock)
const offersLatency = new Trend('search_offers_latency', true); // GET /searches/{id}/offers round trip
const offersPerSearch = new Trend('search_offers_count');
const createAccepted = new Rate('search_create_accepted'); // 202 vs anything else (429 quota, 5xx, ...)
const searchSucceeded = new Rate('search_succeeded'); // completed | partially_completed within the timeout
const searchHasOffers = new Rate('search_has_offers'); // ≥ 1 offer
const searchTimeouts = new Counter('search_timeouts');
const searchStatus = new Counter('search_terminal_status');

export const options = {
  scenarios: {
    searches: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: __ENV.RAMP_UP || '2m', target: MAX_VUS },
        { duration: __ENV.HOLD || '5m', target: MAX_VUS },
        { duration: __ENV.RAMP_DOWN || '1m', target: 0 },
      ],
      gracefulRampDown: `${SEARCH_TIMEOUT_S + 30}s`,
      gracefulStop: `${SEARCH_TIMEOUT_S + 30}s`,
    },
  },
  thresholds: {
    // §23 targets.
    'http_req_duration{name:POST /searches}': ['p(95)<150'],
    'http_req_duration{name:GET /searches/{id}/offers}': ['p(95)<100'],
    search_create_latency: ['p(95)<150'],
    search_offers_latency: ['p(95)<100'],
    search_completion_time: ['p(50)<15000', 'p(95)<45000'],
    // Sanity: the run is meaningless if searches are rejected or fail.
    search_create_accepted: ['rate>0.99'],
    search_succeeded: ['rate>0.95'],
    search_has_offers: ['rate>0.90'],
    'http_req_failed{name:GET /searches/{id}}': ['rate<0.01'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  setupTimeout: '60s',
  noConnectionReuse: false,
};

const headers = {
  Authorization: `Bearer ${API_KEY}`,
  'Content-Type': 'application/json',
  Accept: 'application/json',
};

export function setup() {
  if (!API_KEY) {
    fail('API_KEY is required (a pl_live_... key for a tenant with raised limits; see load/README.md)');
  }
  const ready = http.get(`${API_URL}/ready`, { tags: { name: 'GET /ready' } });
  if (ready.status !== 200) {
    fail(`API not ready: GET ${API_URL}/ready -> ${ready.status} ${ready.body}`);
  }
  const res = http.get(CATALOG_URL, { tags: { name: 'fixture catalog' } });
  if (res.status !== 200) {
    fail(`fixture catalogue unavailable: GET ${CATALOG_URL} -> ${res.status}`);
  }
  // Search for the main products only (accessories are present on the sites as hard negatives).
  const products = res.json('products').filter((p) => !p.accessory);
  if (products.length === 0) {
    fail('fixture catalogue has no products');
  }
  return { products };
}

function pickDomains(product) {
  // Prefer shops that stock the product, then fill up with others (a "no results" page is realistic too).
  const stocked = (product.stocked_by || []).filter((h) => SITES.includes(h));
  const pool = shuffle(stocked).concat(shuffle(SITES.filter((h) => !stocked.includes(h))));
  return pool.slice(0, DOMAINS_PER_SEARCH);
}

function shuffle(list) {
  const a = list.slice();
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

export default function (data) {
  const product = data.products[Math.floor(Math.random() * data.products.length)];
  const body = {
    title: product.title,
    brand: product.brand,
    mpn: product.mpn,
    gtin: product.gtin,
    sources: { domains: pickDomains(product) },
    options: { country: 'US', language: 'en', target_currency: 'USD', max_results: 50, fresh: FRESH },
  };

  const created = http.post(`${API_URL}/searches`, JSON.stringify(body), {
    headers: { ...headers, 'Idempotency-Key': `k6-${exec.vu.idInTest}-${exec.vu.iterationInScenario}-${Date.now()}` },
    tags: { name: 'POST /searches' },
  });
  createLatency.add(created.timings.duration);
  const accepted = check(created, { 'POST /searches -> 202': (r) => r.status === 202 });
  createAccepted.add(accepted);
  if (!accepted) {
    // 429 = quota/concurrency/rate limit: the tenant limits are too low for this run (see README).
    console.warn(`POST /searches -> ${created.status}: ${String(created.body).slice(0, 300)}`);
    sleep(created.status === 429 ? 5 : 1);
    return;
  }

  const id = created.json('data.id');
  const started = Date.now();
  let status = created.json('data.status');
  while (!TERMINAL.includes(status)) {
    if ((Date.now() - started) / 1000 > SEARCH_TIMEOUT_S) {
      searchTimeouts.add(1);
      searchSucceeded.add(false);
      searchHasOffers.add(false);
      http.post(`${API_URL}/searches/${id}/cancel`, null, { headers, tags: { name: 'POST /searches/{id}/cancel' } });
      return;
    }
    sleep(POLL_INTERVAL_S);
    const res = http.get(`${API_URL}/searches/${id}`, { headers, tags: { name: 'GET /searches/{id}' } });
    if (res.status === 200) {
      status = res.json('data.status');
    }
  }
  completionTime.add(Date.now() - started);
  searchStatus.add(1, { status });
  const ok = status === 'completed' || status === 'partially_completed';
  searchSucceeded.add(ok);

  const offers = http.get(`${API_URL}/searches/${id}/offers?per_page=50`, {
    headers,
    tags: { name: 'GET /searches/{id}/offers' },
  });
  offersLatency.add(offers.timings.duration);
  check(offers, { 'GET offers -> 200': (r) => r.status === 200 });
  const count = offers.status === 200 ? (offers.json('data') || []).length : 0;
  offersPerSearch.add(count);
  searchHasOffers.add(count > 0);
}
