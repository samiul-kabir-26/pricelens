import { InMemorySpanExporter } from '@opentelemetry/sdk-trace';
import { errors } from 'playwright';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BlockedAddressError } from '../src/net-policy.js';
import { buildApp, parseTraceparent } from '../src/server.js';
import type { RenderFn, RenderResult } from '../src/render.js';
import { createTracing, noopTracing } from '../src/tracing.js';
import { FakePool, TOKEN, logCapture, stubPolicy, testConfig } from './helpers.js';

const okResult: RenderResult = {
  final_url: 'https://shop.example.com/p/123',
  status: 200,
  html: '<!doctype html><p>ok</p>',
  timing_ms: 12,
  screenshot_base64: null,
  truncated: false,
};

const auth = { authorization: `Bearer ${TOKEN}` };

function setup(opts: { render?: RenderFn; pool?: FakePool; dns?: Record<string, string[]> } = {}) {
  const pool = opts.pool ?? new FakePool();
  const render = opts.render ?? vi.fn<RenderFn>(async () => okResult);
  const app = buildApp({ config: testConfig(), pool, render, logger: false, netPolicy: stubPolicy(opts.dns) });
  return { app, pool, render };
}

let current: ReturnType<typeof setup> | undefined;
afterEach(async () => {
  await current?.app.close();
  current = undefined;
});

function post(body: unknown, headers: Record<string, string> = auth) {
  current ??= setup();
  return current.app.inject({ method: 'POST', url: '/render', headers, payload: body as object });
}

describe('GET /health', () => {
  it('is unauthenticated and reports pool state', async () => {
    current = setup();
    const res = await current.app.inject({ method: 'GET', url: '/health' });
    expect(res.statusCode).toBe(200);
    expect(res.json()).toMatchObject({ status: 'ok', pool: { capacity: 2, active: 0 } });
  });
});

describe('auth', () => {
  it.each([
    ['missing header', {}],
    ['wrong token', { authorization: 'Bearer nope' }],
    ['wrong scheme', { authorization: `Basic ${TOKEN}` }],
    ['token prefix only', { authorization: `Bearer ${TOKEN.slice(0, 5)}` }],
    ['token with suffix', { authorization: `Bearer ${TOKEN}x` }],
  ])('rejects %s with 401', async (_name, headers) => {
    const res = await post({ url: 'https://example.com' }, headers as Record<string, string>);
    expect(res.statusCode).toBe(401);
    expect(res.json()).toEqual({ error: 'unauthorized' });
    expect(current!.render).not.toHaveBeenCalled();
  });

  it('checks auth before validating the body', async () => {
    const res = await post({ nonsense: true }, {});
    expect(res.statusCode).toBe(401);
  });

  it('accepts the correct bearer token (case-insensitive scheme)', async () => {
    const res = await post({ url: 'https://example.com' }, { authorization: `bearer ${TOKEN}` });
    expect(res.statusCode).toBe(200);
  });

  it('rejects everything when the configured token is empty', async () => {
    const app = buildApp({ config: testConfig({ rendererToken: '' }), pool: new FakePool(), logger: false });
    const res = await app.inject({
      method: 'POST',
      url: '/render',
      headers: { authorization: 'Bearer ' },
      payload: { url: 'https://example.com' },
    });
    expect(res.statusCode).toBe(401);
    await app.close();
  });
});

describe('request validation', () => {
  it('returns the spec response shape for a valid full request', async () => {
    const res = await post({
      url: 'https://shop.example.com/p/123',
      wait_until: 'networkidle',
      wait_for_selector: '[data-testid=price]',
      timeout_ms: 20000,
      proxy: { server: 'http://host:8080', username: 'u', password: 'p' },
      block_resources: ['image', 'media', 'font'],
      viewport: { width: 1366, height: 900 },
      locale: 'en-US',
      screenshot: false,
    });
    expect(res.statusCode).toBe(200);
    expect(res.json()).toEqual({
      final_url: 'https://shop.example.com/p/123',
      status: 200,
      html: '<!doctype html><p>ok</p>',
      timing_ms: 12,
      screenshot_base64: null,
    });
    expect(current!.pool.released).toBe(1);
  });

  it.each([
    ['missing url', {}],
    ['non-string url', { url: 42 }],
    ['bad wait_until', { url: 'https://x.test', wait_until: 'idle' }],
    ['timeout too large', { url: 'https://x.test', timeout_ms: 10_000_000 }],
    ['timeout too small', { url: 'https://x.test', timeout_ms: 1 }],
    ['non-integer timeout', { url: 'https://x.test', timeout_ms: 1500.5 }],
    ['string timeout (no coercion)', { url: 'https://x.test', timeout_ms: '5000' }],
    ['string screenshot (no coercion)', { url: 'https://x.test', screenshot: 'true' }],
    ['unknown block resource', { url: 'https://x.test', block_resources: ['document'] }],
    ['duplicate block resource', { url: 'https://x.test', block_resources: ['image', 'image'] }],
    ['viewport missing height', { url: 'https://x.test', viewport: { width: 1000 } }],
    ['viewport too small', { url: 'https://x.test', viewport: { width: 10, height: 10 } }],
    ['proxy without server', { url: 'https://x.test', proxy: { username: 'u' } }],
    ['proxy with bad scheme', { url: 'https://x.test', proxy: { server: 'file:///tmp/sock' } }],
    ['bad locale', { url: 'https://x.test', locale: 'en_US; drop' }],
    ['unknown property', { url: 'https://x.test', cookies: [] }],
  ])('rejects %s with 400', async (_name, body) => {
    const res = await post(body);
    expect(res.statusCode).toBe(400);
    expect(res.json()).toMatchObject({ error: 'invalid_request' });
    expect(current!.render).not.toHaveBeenCalled();
  });

  it('rejects malformed JSON with 400', async () => {
    current = setup();
    const res = await current.app.inject({
      method: 'POST',
      url: '/render',
      headers: { ...auth, 'content-type': 'application/json' },
      payload: '{"url":',
    });
    expect(res.statusCode).toBe(400);
  });
});

describe('URL scheme guard', () => {
  it.each([
    'file:///etc/passwd',
    'FILE:///etc/passwd',
    'data:text/html,<h1>hi</h1>',
    'chrome://settings',
    'chrome-extension://abc/page.html',
    'javascript:alert(1)',
    'about:blank',
    'ftp://example.com/x',
    'view-source:https://example.com',
    'blob:https://example.com/uuid',
    'ws://example.com',
    'not a url',
    '/relative/path',
  ])('rejects %s with 400', async (url) => {
    const res = await post({ url });
    expect(res.statusCode).toBe(400);
    expect(res.json()).toMatchObject({ error: 'invalid_request' });
    expect(current!.render).not.toHaveBeenCalled();
  });

  it.each(['http://example.com', 'https://example.com/p?q=1#x', 'HTTPS://EXAMPLE.COM/'])(
    'accepts %s',
    async (url) => {
      const res = await post({ url });
      expect(res.statusCode).toBe(200);
    },
  );
});

describe('pool saturation and render errors', () => {
  it('returns 503 saturated with Retry-After when no slot is free', async () => {
    current = setup({ pool: new FakePool(0) });
    const res = await post({ url: 'https://example.com' });
    expect(res.statusCode).toBe(503);
    expect(res.headers['retry-after']).toBe('2');
    expect(res.json()).toEqual({ error: 'saturated' });
    expect(current.render).not.toHaveBeenCalled();
  });

  it('maps Playwright timeouts to 504 and releases the slot', async () => {
    current = setup({
      render: async () => {
        throw new errors.TimeoutError('page.goto: Timeout 1000ms exceeded.');
      },
    });
    const res = await post({ url: 'https://example.com' });
    expect(res.statusCode).toBe(504);
    expect(res.json()).toEqual({ error: 'timeout' });
    expect(current.pool.active).toBe(0);
  });

  it('maps other failures to 502 navigation_failed with a one-line message', async () => {
    current = setup({
      render: async () => {
        throw new Error('page.goto: net::ERR_NAME_NOT_RESOLVED at https://nope.invalid/\nCall log:\n  - navigating');
      },
    });
    const res = await post({ url: 'https://nope.invalid/' });
    expect(res.statusCode).toBe(502);
    expect(res.json()).toEqual({
      error: 'navigation_failed',
      message: 'page.goto: net::ERR_NAME_NOT_RESOLVED at https://nope.invalid/',
    });
    expect(current.pool.active).toBe(0);
  });

  it('sets X-Html-Truncated when the HTML was capped', async () => {
    current = setup({ render: async () => ({ ...okResult, truncated: true }) });
    const res = await post({ url: 'https://example.com' });
    expect(res.headers['x-html-truncated']).toBe('true');
    expect(Object.keys(res.json())).not.toContain('truncated');
  });
});

describe('GET /metrics', () => {
  it('exposes pool utilization, request counter and duration histogram', async () => {
    current = setup();
    await post({ url: 'https://example.com' });
    await post({ url: 'https://example.com' }, {});
    const res = await current.app.inject({ method: 'GET', url: '/metrics' });
    expect(res.statusCode).toBe(200);
    expect(res.headers['content-type']).toContain('text/plain');
    const text = res.body;
    expect(text).toContain('# TYPE pricelens_render_pool_utilization gauge');
    expect(text).toMatch(/pricelens_render_requests_total\{status="200"\} 1/);
    expect(text).toMatch(/pricelens_render_requests_total\{status="401"\} 1/);
    expect(text).toContain('# TYPE pricelens_render_duration_seconds histogram');
    expect(text).toMatch(/pricelens_render_duration_seconds_count\{outcome="ok"\} 1/);
  });
});

describe('trace context', () => {
  const tp = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

  it('parses valid traceparent headers and rejects invalid ones', () => {
    expect(parseTraceparent(tp)).toEqual({
      traceparent: tp,
      trace_id: '4bf92f3577b34da6a3ce929d0e0e4736',
      span_id: '00f067aa0ba902b7',
    });
    expect(parseTraceparent(undefined)).toBeNull();
    expect(parseTraceparent('garbage')).toBeNull();
    expect(parseTraceparent('00-00000000000000000000000000000000-00f067aa0ba902b7-01')).toBeNull();
    expect(parseTraceparent('ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01')).toBeNull();
  });

  it('echoes traceparent into structured request logs', async () => {
    const cap = logCapture();
    const app = buildApp({
      config: testConfig(),
      pool: new FakePool(),
      render: async () => okResult,
      logger: { level: 'info', stream: cap.stream },
      netPolicy: stubPolicy(),
    });
    await app.inject({
      method: 'POST',
      url: '/render',
      headers: { ...auth, traceparent: tp, 'x-request-id': 'req-123' },
      payload: { url: 'https://example.com' },
    });
    await app.close();
    const lines = cap.lines().filter((l) => l.reqId === 'req-123');
    expect(lines.length).toBeGreaterThanOrEqual(2);
    for (const l of lines) {
      expect(l).toMatchObject({ traceparent: tp, trace_id: '4bf92f3577b34da6a3ce929d0e0e4736' });
    }
    expect(lines.some((l) => l.msg === 'render ok')).toBe(true);
    expect(JSON.stringify(cap.lines())).not.toContain(TOKEN);
  });
});

describe('lifecycle', () => {
  it('closes the pool when the app closes', async () => {
    const pool = new FakePool();
    const app = buildApp({ config: testConfig(), pool, logger: false });
    await app.close();
    expect(pool.closed).toBe(true);
  });
});

describe('address policy (SSRF)', () => {
  const metricValue = async (kind: string) => {
    const res = await current!.app.inject({ method: 'GET', url: '/metrics' });
    const m = new RegExp(`pricelens_render_blocked_requests_total\\{kind="${kind}"\\} (\\d+)`).exec(res.body);
    return m ? Number(m[1]) : NaN;
  };

  it.each([
    ['private IPv4 literal', 'http://10.0.0.5/admin'],
    ['metadata address', 'http://169.254.169.254/latest/meta-data/'],
    ['loopback IPv6 literal', 'http://[::1]:8080/'],
    ['IPv4-mapped IPv6 literal', 'http://[::ffff:192.168.1.1]/'],
    ['localhost', 'http://localhost:3100/metrics'],
    ['*.internal', 'http://metadata.google.internal/'],
    ['hostname resolving to a private address', 'https://rebind.example/'],
    ['hostname with one private address among public ones', 'https://mixed.example/'],
  ])('rejects %s with 403 before taking a pool slot', async (_name, url) => {
    current = setup({ dns: { 'rebind.example': ['10.1.2.3'], 'mixed.example': ['93.184.215.14', 'fd00::1'] } });
    const res = await post({ url });
    expect(res.statusCode).toBe(403);
    expect(res.json()).toMatchObject({ error: 'blocked_address' });
    expect(res.json().message).toMatch(/blocked by address policy/);
    expect(current.render).not.toHaveBeenCalled();
    expect(current.pool.released).toBe(0);
    expect(await metricValue('document')).toBe(1);
    expect(await metricValue('subresource')).toBe(0);
  });

  it('lets unresolvable hosts through to the browser (502 path, retryable)', async () => {
    current = setup({ dns: { 'nope.example': [] } });
    const res = await post({ url: 'https://nope.example/' });
    expect(res.statusCode).toBe(200);
    expect(current.render).toHaveBeenCalledOnce();
  });

  it('passes the policy to render and maps BlockedAddressError to 403', async () => {
    const render = vi.fn<RenderFn>(async (_b, _req, opts) => {
      expect(opts.netPolicy?.enabled).toBe(true);
      opts.onBlocked?.({ kind: 'subresource', url: 'http://10.0.0.1/x.png', host: '10.0.0.1', reason: 'r', attributed: true });
      opts.onBlocked?.({ kind: 'subresource', url: 'http://10.0.0.2/', host: '10.0.0.2', reason: 'r', attributed: false });
      opts.onBlocked?.({ kind: 'document', url: 'http://10.0.0.3/', host: '10.0.0.3', reason: 'redirect hop', attributed: true });
      throw new BlockedAddressError('10.0.0.3', 'redirect hop');
    });
    current = setup({ render });
    const res = await post({ url: 'https://shop.example/p/1' });
    expect(res.statusCode).toBe(403);
    expect(res.json()).toEqual({
      error: 'blocked_address',
      message: 'request to 10.0.0.3 blocked by address policy: redirect hop',
    });
    expect(current.pool.active).toBe(0);
    expect(await metricValue('document')).toBe(1);
    expect(await metricValue('subresource')).toBe(2);
    const metrics = (await current.app.inject({ method: 'GET', url: '/metrics' })).body;
    expect(metrics).toMatch(/pricelens_render_duration_seconds_count\{outcome="blocked"\} 1/);
  });

  it('builds the policy from config when none is injected', async () => {
    const app = buildApp({
      config: testConfig({ allowHosts: ['127.0.0.1'] }),
      pool: new FakePool(),
      render: async () => okResult,
      logger: false,
    });
    const inject = (url: string) =>
      app.inject({ method: 'POST', url: '/render', headers: auth, payload: { url } });
    expect((await inject('http://127.0.0.1:9/')).statusCode).toBe(200);
    expect((await inject('http://127.0.0.2:9/')).statusCode).toBe(403);
    await app.close();

    const open = buildApp({
      config: testConfig({ blockPrivate: false }),
      pool: new FakePool(),
      render: async () => okResult,
      logger: false,
    });
    const res = await open.inject({ method: 'POST', url: '/render', headers: auth, payload: { url: 'http://10.0.0.1/' } });
    expect(res.statusCode).toBe(200);
    await open.close();
  });
});

describe('OpenTelemetry', () => {
  const tp = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

  it('is a no-op unless OTEL_EXPORTER_OTLP_ENDPOINT is set', () => {
    expect(createTracing({})).toBe(noopTracing);
    const t = createTracing({ OTEL_EXPORTER_OTLP_ENDPOINT: 'http://127.0.0.1:4318' });
    expect(t.enabled).toBe(true);
    return t.shutdown();
  });

  it('records one render span per request as a child of traceparent', async () => {
    const exporter = new InMemorySpanExporter();
    const tracing = createTracing({}, { exporter });
    const render = vi.fn<RenderFn>(async (_b, _req, opts) => {
      opts.onBlocked?.({ kind: 'subresource', url: 'http://10.0.0.1/', host: '10.0.0.1', reason: 'r', attributed: true });
      return okResult;
    });
    const app = buildApp({ config: testConfig(), pool: new FakePool(), render, logger: false, netPolicy: stubPolicy(), tracing });
    const send = (url: string, headers: Record<string, string>) =>
      app.inject({ method: 'POST', url: '/render', headers, payload: { url } });

    await send('https://shop.example/p/1', { ...auth, traceparent: tp });
    await send('http://10.9.9.9/', { ...auth, traceparent: tp });
    await send('https://shop.example/p/2', {}); // 401, no traceparent
    await tracing.forceFlush();

    const spans = exporter.getFinishedSpans();
    expect(spans.map((s) => s.name)).toEqual(['render', 'render', 'render']);
    const [ok, blocked, unauth] = spans;
    expect(ok!.spanContext().traceId).toBe('4bf92f3577b34da6a3ce929d0e0e4736');
    expect(ok!.parentSpanContext?.spanId).toBe('00f067aa0ba902b7');
    expect(ok!.attributes).toMatchObject({
      'url.host': 'shop.example',
      'http.status': 200,
      'render.timing_ms': 12,
      'render.blocked_requests': 1,
    });
    expect(blocked!.attributes).toMatchObject({ 'url.host': '10.9.9.9', 'http.status': 403, 'render.blocked_requests': 1 });
    expect(unauth!.parentSpanContext).toBeUndefined();
    expect(unauth!.attributes).toMatchObject({ 'http.status': 401 });
    await app.close();
  });
});
