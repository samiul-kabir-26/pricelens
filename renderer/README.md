# PriceLens Renderer

An internal headless-rendering service used by the Laravel `render` queue (spec §6.3). It runs on Node 22+, Fastify 5 and Playwright Chromium. It must stay on the internal network and never be exposed publicly.

## Run

```bash
npm install
npx playwright install chromium
cp .env.example .env   # set RENDERER_TOKEN
RENDERER_TOKEN=dev-secret npm run dev        # tsx watch
npm run build && RENDERER_TOKEN=... npm start
```

| Env | Default | |
|---|---|---|
| `PORT` | `3100` | |
| `RENDERER_TOKEN` | — | Required. Without it the service refuses to start, unless `NODE_ENV=test` |
| `BROWSER_INSTANCES` | `4` | Chromium processes launched at startup |
| `CONTEXTS_PER_INSTANCE` | `4` | Concurrent contexts per process |
| `DEFAULT_TIMEOUT_MS` | `20000` | Used when `timeout_ms` is omitted (allowed range 500–120000) |
| `HOST` / `LOG_LEVEL` | `0.0.0.0` / `info` | Optional |
| `RENDERER_BLOCK_PRIVATE` | `true` | Enforce the address policy (see [Address policy](#address-policy-ssrf)). `false` is refused when `NODE_ENV=production`, which is also the default `NODE_ENV` |
| `RENDERER_ALLOW_HOSTS` | — | Comma list of hosts that bypass the address policy: exact hosts or IP literals (`127.0.0.1`, `[::1]`) or `*.suffix` wildcards that match subdomains only (`*.fixture.test`). Meant for tests and the load-test fixture farm |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | — | When set, enables OpenTelemetry tracing over OTLP/HTTP. The other standard `OTEL_EXPORTER_OTLP_*` variables and `OTEL_SERVICE_NAME` (default `pricelens-renderer`) apply |

## API

`POST /render` takes `Authorization: Bearer <RENDERER_TOKEN>`. The request and response bodies match spec §6.3. Unknown fields are rejected, and only `http`/`https` URLs are accepted.

| Status | Body | When |
|---|---|---|
| 200 | `{ final_url, status, html, timing_ms, screenshot_base64 }` | Rendered. `status` is the target's HTTP status. HTML is capped at 5 MB, and a capped response carries `X-Html-Truncated: true` |
| 400 | `{ error: "invalid_request", message }` | Schema violation or a non-http(s) URL |
| 401 | `{ error: "unauthorized" }` | Missing or wrong token |
| 403 | `{ error: "blocked_address", message }` | The address policy blocked the main document. That covers the initial URL, any redirect hop of it, and a later script navigation of the main frame |
| 502 | `{ error: "navigation_failed", message }` | DNS, connection or TLS error, or a browser crash. If the renderer's own DNS lookup fails, the request fails as `net::ERR_NAME_NOT_RESOLVED`, so it stays retryable |
| 503 | `{ error: "saturated" }` + `Retry-After: 2` | Every context slot is busy. The request is not queued |
| 504 | `{ error: "timeout" }` | Navigation or `wait_for_selector` ran past `timeout_ms` |

Defaults: `wait_until=load`, no blocking, Playwright's default viewport, `screenshot=false`.

Every request gets a fresh `BrowserContext`, which is closed afterwards, so no state carries over between tenants. Each context runs with `acceptDownloads: false` and service workers blocked. Requested resource types are aborted through `context.route` (WebSockets through `routeWebSocket`), and so are requests to non-http(s) schemes and to blocked addresses (see [Address policy](#address-policy-ssrf)). If a Chromium instance crashes, it is relaunched with backoff.

These endpoints need no authentication:

- `GET /health` is the liveness check and reports pool state.
- `GET /metrics` serves Prometheus metrics: `pricelens_render_pool_utilization`, `pricelens_render_pool_ready_instances`, `pricelens_render_requests_total{status}`, `pricelens_render_duration_seconds{outcome}` (outcome `ok`, `timeout`, `navigation_failed` or `blocked`), `pricelens_render_blocked_requests_total{kind}`, plus default process metrics under the `pricelens_renderer_` prefix.
  - `kind="document"` counts blocks of the rendered page's main-frame navigation.
  - `kind="subresource"` counts everything else: images, scripts, XHR/fetch, WebSockets, iframes and popups.

Logs are pino JSON on stdout. A valid W3C `traceparent` header is added to every request-scoped log line (`traceparent`, `trace_id`, `span_id`), as is `X-Request-Id` (as `reqId`).

When `OTEL_EXPORTER_OTLP_ENDPOINT` is set, each `POST /render` produces one `render` span (kind SERVER). The span is a child of the incoming `traceparent` and is exported over OTLP/HTTP with a batch processor. It carries these attributes: `url.host`, `http.status` (the renderer's response status), `render.timing_ms`, `render.target_status` and `render.blocked_requests`. Spans with a 5xx status are marked as errors. No global tracer is registered and no auto-instrumentation is installed. The trace context is never forwarded to target sites.

On SIGTERM or SIGINT the service stops accepting requests, waits for in-flight renders to finish (up to 15 s), then closes the browsers. It force-exits after 30 s.

## Test

```bash
npx tsc --noEmit
npx vitest run
```

Integration tests run real Chromium against a local `node:http` fixture site. They cover SPA pricing, `wait_for_selector`, timeouts, image blocking, saturation and crash relaunch. These tests run with `allowHosts: ['127.0.0.1', 'localhost']`, the equivalent of `RENDERER_ALLOW_HOSTS=127.0.0.1,localhost`. `test/ssrf.integration.test.ts` maps `*.fixture.test` and `*.evil.test` to the fixture server through Chromium's `--host-resolver-rules`. The policy's injectable resolver maps `*.evil.test` to private addresses, so a request that leaked would really reach the fixture server and show up in its request log. If Chromium isn't installed, the integration tests are skipped.

## Docker

```bash
# from the repo root
docker build -f docker/renderer/Dockerfile -t pricelens/renderer .
docker run --init -p 3100:3100 -e RENDERER_TOKEN=... pricelens/renderer
```

The image is based on `mcr.microsoft.com/playwright:v1.64.1-noble`. Keep that tag in step with the `playwright` version in `package.json`. The container runs as `pwuser`.

## Address policy (SSRF)

The Laravel `SafeUrlGuard` checks the initial URL and the final URL (spec §6.3, §15). The renderer also enforces its own address policy on everything the browser fetches. That covers navigations, every redirect hop, subresources, XHR/fetch, iframes, popups and WebSockets.

A host is blocked in either of two cases:

- Its name is `localhost`, `*.localhost`, `*.local` or `*.internal`.
- **Any** of its addresses falls in one of these ranges:
  - IPv4: `0.0.0.0/8`, `10/8`, `100.64/10`, `127/8`, `169.254/16`, `172.16/12`, `192.0.0/24`, `192.0.2/24`, `192.168/16`, `198.18/15`, `198.51.100/24`, `203.0.113/24`, `224/4`, `240/4`, `255.255.255.255`
  - IPv6: `::/128`, `::1`, `64:ff9b::/96`, `100::/64`, `2001:db8::/32`, `fc00::/7`, `fe80::/10`, `ff00::/8`
  - IPv4-mapped IPv6 (`::ffff:a.b.c.d`), which is checked as the IPv4 address

The policy checks IP literals directly, including bracketed IPv6 and Chromium-canonicalized forms such as `0x7f.1`. It resolves hostnames with `dns.lookup(host, { all: true, verbatim: true })` and caches the results for 30 s. Failed lookups are not cached. `data:` and `blob:` are allowed. Any other non-http(s) scheme is aborted, as before. Hosts that match `RENDERER_ALLOW_HOSTS` bypass all of these checks.

Enforcement has three layers, all in `src/render.ts`:

1. **Pre-check.** `POST /render` checks the initial URL before it takes a pool slot. A blocked URL gets a 403.
2. **`context.route('**/*')`.** This is the same handler that does resource-type blocking. It checks the first request of every chain and aborts blocked ones with `blockedbyclient`. If the blocked request is the main frame's navigation, the render becomes a 403.
3. **Redirect guard (`src/redirect-guard.ts`).** Playwright never calls `context.route` for redirect hops. Chromium pauses each hop, but Playwright continues it automatically, for navigations and subresources alike. `test/ssrf.integration.test.ts` checks this behaviour. To cover the hops, each Chromium process gets one browser-level CDP session with `Fetch.enable`. That session sees requests from every page, OOPIF and worker. Non-redirect requests pass through untouched. Each redirect hop is checked against the policy before Chromium connects. A blocked hop of the main document becomes a 403.

WebSocket handshakes go through neither `route` nor CDP `Fetch`. When the policy is on, they are routed with `context.routeWebSocket`. Blocked sockets are closed with code 1008. Allowed sockets connect from the page itself, through the browser's network stack and proxy.

`--host-resolver-rules` is **not** used. It matches hostnames, not resolved addresses, so it cannot express "any name that resolves to 10/8". It would also break the allowlisted loopback fixtures.

### Residual risks

- **DNS rebinding (TOCTOU).** The renderer resolves a name, and Chromium then resolves it again on its own. A hostile DNS server can answer with a public address to us and a private one to Chromium a moment later. The 30 s cache narrows this window but does not close it. Our check cannot pin the IP that Chromium connects to.
- **Requests outside the routed paths.** WebSockets opened from workers are not covered, because `routeWebSocket` only shims pages. Neither are DNS prefetch, preconnect or WebRTC/STUN traffic.
- **Behaviour change for WebSockets.** With the policy on, `routeWebSocket` installs an in-page `WebSocket` shim, which pages can detect.
- **Unattributed redirect hops.** A blocked redirect hop inside an iframe or worker is still blocked and counted in the metric. It is not attributed to a specific request in logs or spans.

**A network-level egress policy is still recommended.** The renderer container should not be able to reach private networks, metadata endpoints or internal services, whatever this policy does.
