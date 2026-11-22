# Load testing

k6 drives 200 concurrent searches against a **fixture farm**: ten static shops (`shop-01.fixture.test` …
`shop-10.fixture.test`) served by one nginx container. The thresholds are the spec §23 targets.

```
load/fixture-farm/   generate.py (stdlib Python) + nginx config + Dockerfile
load/k6/searches.js  the k6 scenario
load/provision-sources.sh   creates one `site` source per fixture shop for the load tenant
docker-compose.loadtest.yml (repo root)   fixture-farm + SSRF/renderer allow-lists + k6 (profile `loadtest`)
```

## The fixture farm

`generate.py` builds each shop with:

- `/search?q=…`: nginx maps the raw query (model token or GTIN) to a static results page. The page lists the
  target product first, then accessories and related items. Unknown queries get a page listing every product.
- ~20 product pages under `/p/<slug>`. Most use schema.org JSON-LD (plain `Product`/`Offer`, `@graph` with
  breadcrumbs, or `AggregateOffer`). Some use microdata, and some carry only Open Graph / `product:*` meta tags.
  GTINs are synthetic but have valid GS1 check digits (UPC-A and EAN-13). Prices vary ±10 % per shop. A few pages are
  out of stock, pre-order or refurbished.
- Hard negatives: XM5 ear pads, a Switch OLED case, an MX Master cable, and the WH-1000XM4 next to the XM5.
- `robots.txt` (everything allowed except cart/checkout/account), `sitemap.xml`, `/img/<slug>.png`, and
  `/catalog.json`, which k6 reads to pick search inputs.

The output is deterministic for a given `--seed`. Check it locally:

```bash
python3 load/fixture-farm/generate.py --out /tmp/farm          # inspect /tmp/farm/sites/...
docker build -t pricelens/fixture-farm:dev load/fixture-farm
docker run --rm -p 8090:80 pricelens/fixture-farm:dev
curl -H 'Host: shop-01.fixture.test' 'localhost:8090/search?q=Sony%20WH-1000XM5'
curl -H 'Host: shop-01.fixture.test' localhost:8090/p/sony-wh-1000xm5
```

To use a different number of shops, change `FIXTURE_SITES` in `docker-compose.loadtest.yml` and regenerate the
network aliases with `python3 load/fixture-farm/generate.py --sites N --print-aliases`.

## Running

All commands run from the repository root. Use a **non-production** stack: the overlay lets the scraper and
renderer reach the farm's private IP. The API ignores `PRICELENS_SSRF_ALLOW_HOSTS` when `APP_ENV=production`.

```bash
alias dcl='docker compose -f docker-compose.yml -f docker-compose.loadtest.yml'

# 1. Stack + fixture farm. The overlay also raises the per-domain throttle (SCRAPER_DEFAULT_RPS_PER_DOMAIN=200,
#    concurrency 50) and the circuit-breaker threshold for *.fixture.test runs.
dcl up -d --build
dcl run --rm migrate                      # first time only

# 2. A dedicated tenant on the enterprise plan. Note the printed "API key (shown once)".
dcl run --rm api php artisan pricelens:tenant:create "Load" --plan=enterprise
export LOADTEST_API_KEY=pl_live_...

# 3. Raise the tenant's limits. Enterprise allows 100 concurrent searches, 3000 req/min and 400 concurrent tasks.
#    200 VUs need more. Overrides live in tenants.settings.limits.* and win over the plan.
dcl run --rm api php artisan tinker --execute '
  $t = App\Models\Tenant::where("name", "Load")->latest("id")->firstOrFail();
  $t->settings = array_replace_recursive($t->settings ?? [], ["limits" => [
      "concurrent_searches" => 400, "api_rpm" => 100000, "concurrent_tasks" => 5000,
      "searches_per_month" => 10000000, "max_sources_per_search" => 100,
  ]]);
  $t->save();
  echo "limits raised for {$t->ulid}\n";'

# 4. One `site` source per shop, so discovery uses http://shop-NN.fixture.test/search?q={query}.
#    Without a source, un-configured domains get the https-only generic templates.
API_URL=http://localhost:${HTTP_PORT:-8080}/api/v1 API_KEY=$LOADTEST_API_KEY ./load/provision-sources.sh 10

# 5. Smoke run first (5 VUs, ~1.5 min), then the full run (200 VUs: 2 min ramp, 5 min hold, 1 min ramp-down).
dcl --profile loadtest run --rm -e MAX_VUS=5 -e RAMP_UP=20s -e HOLD=1m -e RAMP_DOWN=10s k6
dcl --profile loadtest run --rm k6
```

Scale the workers before the full run, or completion time will measure queueing rather than the pipeline:
`dcl up -d --scale horizon=4`. In `APP_ENV=local`, Horizon runs one `all` supervisor capped at 8 processes per
container. The `production` supervisors scale `scrape` up to 64 processes, but `production` also disables the
SSRF allow-list. So for a meaningful 200-VU run, scale `horizon` replicas rather than changing `APP_ENV`. To watch queues, render pool and stage outcomes while the test runs, add the observability overlay
(`-f docker-compose.observability.yml`, Grafana on http://localhost:3001, dashboard "PriceLens pipeline").

### k6 environment

| Variable | Default | |
|---|---|---|
| `API_URL` | `http://nginx/api/v1` | set via `LOADTEST_API_URL` in the overlay |
| `API_KEY` | (required) | set via `LOADTEST_API_KEY` |
| `SITES` | `shop-01…shop-10.fixture.test` | comma list (`LOADTEST_SITES`) |
| `CATALOG_URL` | `http://<first site>/catalog.json` | |
| `DOMAINS_PER_SEARCH` | `5` | shops that stock the product come first |
| `MAX_VUS` | `200` | peak concurrent VUs = concurrent searches |
| `RAMP_UP` / `HOLD` / `RAMP_DOWN` | `2m` / `5m` / `1m` | |
| `POLL_INTERVAL_S` / `SEARCH_TIMEOUT_S` | `1` / `120` | timed-out searches are cancelled and count as failures |
| `FRESH` | `false` | `true` bypasses the snapshot cache, so every search really fetches |

### Metrics and thresholds

| Metric | Threshold | Source |
|---|---|---|
| `search_create_latency`, `http_req_duration{name:POST /searches}` | p95 < 150 ms | §23 |
| `search_offers_latency`, `http_req_duration{name:GET /searches/{id}/offers}` | p95 < 100 ms | §23 (cached) |
| `search_completion_time` (202 → terminal status, wall clock) | p50 < 15 s, p95 < 45 s | §23 |
| `search_create_accepted` | > 99 % | a 429 means the tenant limits are too low |
| `search_succeeded` (`completed` or `partially_completed`) | > 95 % | |
| `search_has_offers` (≥ 1 offer) | > 90 % | |

`search_completion_time` is measured by polling, so it can be up to `POLL_INTERVAL_S` too high. k6 exits non-zero
if any threshold fails.

### Caveats

- The farm serves static files from one nginx with no artificial latency, so fetch times are better than on the
  real web. The test measures orchestration, queueing, extraction and matching more than network behaviour.
- Pages are static, so `render: never` is set on the sources. To load the renderer instead, set `render` to
  `always` in `provision-sources.sh`. The renderer gets `RENDERER_ALLOW_HOSTS=*.fixture.test`.
- With `FRESH=false`, repeated product pages come from the snapshot cache after the first few minutes. This is
  realistic for popular products. Run with `FRESH=true` for a cold-path number.
