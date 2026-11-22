# PriceLens

Multi-tenant product price-intelligence microservice, built from [`price-intelligence-service.md`](price-intelligence-service.md).
Give it a product (title, image, GTIN/UPC/EAN, MPN, brand or a reference URL). It finds matching listings on
tenant-defined sites and search engines, extracts and normalizes the offers, scores each match with an explainable
confidence, and returns a ranked list through a REST API, signed webhooks and Reverb real-time events.

```
api/        Laravel 13 API + queue workers (pipeline, monitoring, webhooks, metering)
renderer/   Node 22 + Fastify + Playwright service that renders JS-heavy pages (internal only)
web/        Next.js 16 demo UI (BFF pattern: the API key stays server-side)
docker/     Dockerfiles for php / nginx / renderer / web
openapi.yaml  API contract (the UI generates types from it: `cd web && npm run gen:types`)
```

## Quick start (Docker)

```bash
cp .env.example .env
# fill APP_KEY (cd api && php artisan key:generate --show), REVERB_APP_KEY, REVERB_APP_SECRET, RENDERER_TOKEN
docker compose up -d --build
docker compose run --rm migrate      # migrations + plans + global adapters + demo tenant; prints the demo API key
# put that key in .env as PRICELENS_API_KEY, then:
docker compose up -d web
```

- UI: http://localhost:8080
- API: http://localhost:8080/api/v1 (`Authorization: Bearer pl_live_...`)
- Horizon: http://localhost:8080/horizon (local env only)
- Metrics: http://localhost:8080/metrics

Try a search against the bundled sandbox adapter:

```bash
curl -X POST localhost:8080/api/v1/searches -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -d '{
  "title": "A Light in the Attic",
  "reference_url": "https://books.toscrape.com/catalogue/a-light-in-the-attic_1000/index.html",
  "sources": { "domains": ["books.toscrape.com"] },
  "options": { "target_currency": "USD" }
}'
```

Provision real tenants with `php artisan pricelens:tenant:create "Acme" --plan=pro --owner-email=ops@acme.test`.

## Development

```bash
cd api && composer install && ./vendor/bin/pest      # SQLite in-memory, no network (Http::fake + fake DNS)
cd renderer && npm ci && npx vitest run              # includes real-Chromium integration tests
cd web && npm ci && npx vitest run && npx playwright test
```

Add extractor golden fixtures from real pages with `php artisan fixtures:capture {url} [--source=ULID] [--render]`.
Every `api/tests/Fixtures/html/*.expected.json` is replayed by `tests/Unit/ExtractorGoldenTest.php`.

## How a search flows

`POST /searches` → `PlanSearchJob` (normalize query, GTIN/model/brand/attributes, image ingest + pHash, reverse
image, build plan) → `Bus::batch` of `DiscoverJob` (site search, search/shopping engines, reverse image, feeds,
reference URL) → `FetchCandidateJob` / `RenderCandidateJob` (SSRF guard → domain policy → robots.txt → snapshot cache →
circuit breaker → per-domain throttle → fetch → snapshot) → `ExtractOfferJob` (JSON-LD → microdata/RDFa → meta →
embedded state → adapter selectors → LLM fallback; automatic hand-off to the renderer when a page looks
client-rendered) → `OfferRecorder` (locale-aware price parsing, ECB FX, availability/condition mapping, matching
and scoring, upsert, broadcast) → `FinalizeSearchJob` (SKU dedupe, MAD outliers, stats, status, webhooks, monitor
snapshots + alerts).

The key code lives in `api/app/Scraping/*` (pipeline), `api/app/Tenancy/*` (isolation), `api/app/Jobs/*` and
`api/app/Jobs/Middleware/*` (TenantAware → TenantFairness → EnsureSearchNotCancelled → DomainCircuitBreaker →
DomainThrottle).

## Operations

| Area | Where |
|---|---|
| CI (lint, Larastan level 8, Pest, renderer/web tests, OpenAPI lint, image builds → GHCR) | `.github/workflows/ci.yml` |
| Release workflow (pull → migrate → optimize → rolling restart → horizon/reverb restart → `/ready` + canary search) | `scripts/deploy.sh` |
| Prometheus, alert rules (§17), Grafana dashboard "PriceLens pipeline", OTel collector | `docker-compose.observability.yml`, `docker/observability/` |
| Load testing (k6, 200 VUs, §23 thresholds) against a generated fixture farm | `docker-compose.loadtest.yml`, `load/` |
| OpenAPI spec (generated from curated definitions; every API response in the test suite is validated against it) | `tools/openapi/generate.py` → `openapi.yaml` |

Useful commands: `php artisan pricelens:tenant:create`, `pricelens:fx:refresh`, `pricelens:partitions` (monthly
`price_snapshots` partitions; also scheduled daily), `fixtures:capture {url}`. API checks: `composer lint`,
`composer analyse`, `composer test`.

**Auth.** Machine clients use tenant API keys (`pl_live_...`, abilities). Dashboard users use Sanctum: `POST /auth/token`
(Bearer) or `POST /auth/login` (SPA session), with `me`, `switch-tenant`, `logout`, `forgot-password` and
`reset-password`. Roles (owner/admin/member/viewer) map to abilities, and owners/admins manage `/members`.
Self-serve signup (`POST /auth/register`) is off unless `PRICELENS_SELF_SIGNUP=true`.

**Callbacks.** `options.callback_url` gets a signed `search.completed` / `search.failed` delivery (same signature
scheme and retry schedule as webhooks). Create the signing secret first with `POST /callbacks/secret`.

**Observability.** OpenTelemetry spans for HTTP requests, every pipeline job (tenant, search, source, domain and
stage attributes) and the renderer, all in one trace via W3C `traceparent`. Sentry is tagged per tenant, with PII
off. There are Prometheus metrics, including queue depth and wait, plus JSON logs with request ids.

## What is verified

- **API:** 186 Pest tests. Every `/api/v1` response they produce is validated against `openapi.yaml`.
  - Unit: price-parser locale matrix, GTIN and identifiers, matching and hard negatives, SSRF guard, webhook
    signing, extractor golden fixtures, Google service-account JWT.
  - Pipeline (faked HTTP): full run, robots.txt, 403s, renderer hand-off, shopping + FX, CSV export.
  - Platform: idempotency, quotas, per-tenant rate limiting, cross-tenant 404s on 26 routes, monitoring and
    alerts, webhooks and callbacks, dashboard auth and members, tracing propagation.
- **Static analysis:** Larastan level 8 reports 0 errors, with no baseline and no ignores. Pint is clean.
- **Renderer:** 194 tests, including real-Chromium SSRF tests (redirect hops, subresources, websockets).
- **UI:** 101 unit tests plus Playwright E2E, against mocked API responses.
- **Live stack (docker compose, MySQL 8.4, Redis, Horizon):**
  - Real searches with live throttling and FX.
  - Monthly partitions created on MySQL.
  - Dashboard tokens and members.
  - SSRF blocked in both the API and the renderer.
  - The UI BFF, CSV export, broadcast auth and the Reverb websocket through nginx.
  - Prometheus scraping 4 targets with 8 alert rules loaded, Grafana up, and traces reaching the OTel collector.
- **k6 smoke run (5 VUs, fixture farm):** every §23 threshold passed:

  | Metric | Target | Result |
  |---|---|---|
  | Search completion p50 | < 15 s | 12.3 s |
  | Search completion p95 | < 45 s | 16.2 s |
  | `POST /searches` p95 | < 150 ms | 114 ms |
  | Offers p95 | < 100 ms | 29 ms |
  | Failed requests | < 1% | 0% |

  The full 200-VU run is scripted but needs production-sized workers to be meaningful.

## Deliberate choices and deviations

- **Concurrent searches:** counted from the database (`queued` and `running` searches), not a Redis counter, so the
  count can't drift after crashes.
- **Per-domain throttling and circuit breakers are shared across tenants.** Politeness is owed to the target site.
- **Throttled jobs are released, not failed.** Pipeline jobs use `retryUntil` (20 min) and count real attempts on the
  task row.
- **Pennant uses the `array` store.** Flags come from plan features plus tenant overrides (`settings.features.*`).
- **LLM fallback:** uses the official Anthropic PHP SDK with JSON-schema structured output and the server-side
  `fallbacks: "default"` refusal fallback. The model is set by `PRICELENS_LLM_MODEL` (default `claude-opus-5-5`, effort
  `low`). `claude-haiku-5-5` is far cheaper for bulk extraction if quality holds on your fixtures.
- **Outlier band:** median ± max(3×MAD, 15% of the median).
- **Engine discovery stops at the first query variant that returns results**, to save metered provider calls.
- **The SSRF allowlist** (`PRICELENS_SSRF_ALLOW_HOSTS`, `RENDERER_ALLOW_HOSTS`) exists only for fixture farms and
  canaries, and is ignored or refused in production.
- **Renderer address blocking:**
  - It covers documents, redirect hops (via the Chrome DevTools Protocol, because Playwright doesn't route redirect
    hops), subresources and websockets.
  - DNS rebinding between our DNS lookup and Chromium's is a residual risk, so keep a network-level egress policy.
- **Spec open items, decided as defaults:**
  - #1 markets: only two scraping sandboxes are seeded in the global adapter library.
  - #2: tenants are admin-provisioned, self-signup is behind a flag, and there's no billing integration.
  - #3: BYO and platform proxy pools are supported, but none are configured.

## Known limits

- **Providers not exercised:** SerpApi, Brave and Google Vision ran only against faked responses (no keys here).
  Confirm current terms, pricing and response shapes before production (spec §8).
- **Not run yet:** the CI workflow hasn't run on GitHub, and `scripts/deploy.sh` has only been dry-run.
- **Mocked UI tests:** the UI's E2E tests use a mocked API. The live stack was exercised through the BFF with curl,
  not a browser.
