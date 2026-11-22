import { existsSync } from 'node:fs';
import type { FastifyInstance } from 'fastify';
import { chromium } from 'playwright';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { BrowserPool } from '../src/browser-pool.js';
import { BlockedAddressError, NetPolicy } from '../src/net-policy.js';
import type { BlockedRequest } from '../src/redirect-guard.js';
import { render } from '../src/render.js';
import { buildApp } from '../src/server.js';
import { startFixtureServer, type FixtureServer } from './fixtures/server.js';
import { TOKEN, stubResolver, testConfig } from './helpers.js';

function chromiumInstalled(): boolean {
  try {
    return existsSync(chromium.executablePath());
  } catch {
    return false;
  }
}

/**
 * Topology: one loopback fixture server answers for every hostname. Chromium
 * maps *.fixture.test and *.evil.test to 127.0.0.1 (--host-resolver-rules), so
 * a request to internal.evil.test that is NOT blocked really reaches the
 * fixture and shows up in its request log. The policy allowlists
 * *.fixture.test ("public" sites) and resolves *.evil.test, via the injectable
 * resolver, to private addresses.
 */
describe.skipIf(!chromiumInstalled())('address policy against a real Chromium', () => {
  let fixtures: FixtureServer;
  let pool: BrowserPool;
  let app: FastifyInstance;
  let shop: string;
  let cdn: string;
  let evil: string;
  let rebind: string;
  const resolver = stubResolver({ 'internal.evil.test': ['127.0.0.1'], 'rebind.evil.test': ['93.184.215.14', '10.20.30.40'] });
  const policy = new NetPolicy({ blockPrivate: true, allowHosts: ['*.fixture.test'], resolver: resolver.resolve });

  beforeAll(async () => {
    fixtures = await startFixtureServer();
    shop = `http://shop.fixture.test:${fixtures.port}`;
    cdn = `http://cdn.fixture.test:${fixtures.port}`;
    evil = `http://internal.evil.test:${fixtures.port}`;
    rebind = `http://rebind.evil.test:${fixtures.port}`;
    pool = new BrowserPool({
      instances: 1,
      contextsPerInstance: 2,
      launch: () =>
        chromium.launch({
          headless: true,
          args: ['--host-resolver-rules=MAP *.fixture.test 127.0.0.1, MAP *.evil.test 127.0.0.1'],
        }),
    });
    await pool.start();
    app = buildApp({ config: testConfig({ defaultTimeoutMs: 10_000 }), pool, netPolicy: policy, logger: false });
  });

  afterAll(async () => {
    await app?.close();
    await fixtures?.close();
  });

  const renderReq = (body: Record<string, unknown>) =>
    app.inject({ method: 'POST', url: '/render', headers: { authorization: `Bearer ${TOKEN}` }, payload: body });

  const blockedMetric = async (kind: 'document' | 'subresource') => {
    const text = (await app.inject({ method: 'GET', url: '/metrics' })).body;
    const m = new RegExp(`pricelens_render_blocked_requests_total\\{kind="${kind}"\\} (\\d+)`).exec(text);
    return Number(m?.[1]);
  };

  const evilHits = () => fixtures.requests().filter((r) => /^[^ ]*\.evil\.test[: ]/.test(r)); // by Host header

  it('premise: Playwright does not call context.route for redirect hops', async () => {
    // This is why render() needs the browser-level CDP guard on top of context.route.
    const lease = pool.acquire()!;
    const context = await lease.browser.newContext();
    try {
      const routed: string[] = [];
      await context.route('**/*', (route) => {
        if (route.request().resourceType() === 'document') routed.push(route.request().url());
        return route.continue();
      });
      const page = await context.newPage();
      const res = await page.goto(`${shop}/to?url=${encodeURIComponent(`${cdn}/to?url=/product`)}`);
      expect(res?.status()).toBe(200);
      expect(page.url()).toBe(`${cdn}/product`);
      // Three document requests hit the server; only the first was routed.
      expect(fixtures.requests().filter((r) => r.includes('/to?url=') || r.endsWith('/product')).length).toBeGreaterThanOrEqual(3);
      expect(routed).toEqual([`${shop}/to?url=${encodeURIComponent(`${cdn}/to?url=/product`)}`]);
    } finally {
      await context.close();
      lease.release();
    }
  });

  it('(a) renders a page whose <img>, redirected <img>, fetch() and WebSocket target a blocked host', async () => {
    const before = await blockedMetric('subresource');
    const res = await renderReq({ url: `${shop}/ssrf-page?target=${encodeURIComponent(evil)}`, wait_until: 'networkidle' });
    expect(res.statusCode).toBe(200);
    const html = res.json().html as string;
    expect(html).toContain('Public Widget');
    expect(html).toContain('<p id="fetch">fetch-failed</p>');
    expect(html).toContain('<p id="ws">ws-closed</p>');
    // The redirecting <img> hop on the allowed origin happened; nothing reached the blocked host.
    expect(fixtures.requests().some((r) => r.startsWith(`shop.fixture.test:${fixtures.port} /to?url=`))).toBe(true);
    expect(evilHits()).toEqual([]);
    expect((await blockedMetric('subresource')) - before).toBe(4);
    expect(await blockedMetric('document')).toBe(0);
  });

  it('(a, control) the same page DOES reach the host when it is allowlisted', async () => {
    const open = new NetPolicy({ blockPrivate: true, allowHosts: ['*.fixture.test', '*.evil.test'], resolver: resolver.resolve });
    const lease = pool.acquire()!;
    const events: BlockedRequest[] = [];
    try {
      const result = await render(
        lease.browser,
        { url: `${shop}/ssrf-page?target=${encodeURIComponent(evil)}`, wait_until: 'networkidle' },
        { defaultTimeoutMs: 10_000, netPolicy: open, onBlocked: (e) => events.push(e) },
      );
      expect(result.html).toContain('fetch-ok');
    } finally {
      lease.release();
    }
    expect(events).toEqual([]);
    const hits = evilHits().map((r) => r.replace(/^\S+ /, ''));
    expect(hits).toEqual(expect.arrayContaining(['/img/direct.png', '/img/redirected.png', '/api/secrets', '/socket [upgrade]']));
  });

  it('(b) a navigation that 302-redirects to a blocked host returns 403 blocked_address', async () => {
    const seen = evilHits().length;
    const before = await blockedMetric('document');
    const res = await renderReq({ url: `${shop}/to?url=${encodeURIComponent(`${evil}/product`)}` });
    expect(res.statusCode).toBe(403);
    expect(res.json()).toEqual({ error: 'blocked_address', message: expect.stringContaining('internal.evil.test') });
    expect(res.json().message).toMatch(/127\.0\.0\.1/);
    expect(evilHits().length).toBe(seen);
    expect((await blockedMetric('document')) - before).toBe(1);
    expect(pool.active).toBe(0);
  });

  it('(b) ...also on a later hop of a multi-hop chain, and when only one resolved address is private', async () => {
    const seen = evilHits().length;
    const url = `${shop}/to?url=${encodeURIComponent(`${cdn}/to?url=${encodeURIComponent(`${rebind}/product`)}`)}`;
    const res = await renderReq({ url });
    expect(res.statusCode).toBe(403);
    expect(res.json().error).toBe('blocked_address');
    expect(res.json().message).toMatch(/rebind\.evil\.test resolves to 10\.20\.30\.40/);
    expect(evilHits().length).toBe(seen);
  });

  it('(b) allowed redirect chains still render normally', async () => {
    const res = await renderReq({ url: `${shop}/to?url=${encodeURIComponent(`${cdn}/product`)}` });
    expect(res.statusCode).toBe(200);
    expect(res.json().final_url).toBe(`${cdn}/product`);
    expect(res.json().html).toContain('$19.99');
  });

  it('(c) direct navigation to a blocked literal returns 403 without a browser slot', async () => {
    const started = Date.now();
    const res = await renderReq({ url: 'http://10.255.255.1/', timeout_ms: 5_000 });
    expect(res.statusCode).toBe(403);
    expect(res.json()).toEqual({
      error: 'blocked_address',
      message: 'request to 10.255.255.1 blocked by address policy: address 10.255.255.1 is in blocked range 10.0.0.0/8',
    });
    expect(Date.now() - started).toBeLessThan(1_000);
  });

  it('(c) render() itself blocks a blocked main document in the browser (route layer)', async () => {
    const lease = pool.acquire()!;
    const events: BlockedRequest[] = [];
    try {
      const started = Date.now();
      await expect(
        render(lease.browser, { url: 'http://10.255.255.1/', timeout_ms: 5_000 }, {
          defaultTimeoutMs: 5_000,
          netPolicy: policy,
          onBlocked: (e) => events.push(e),
        }),
      ).rejects.toBeInstanceOf(BlockedAddressError);
      expect(Date.now() - started).toBeLessThan(3_000); // aborted before any connection attempt
    } finally {
      lease.release();
    }
    expect(events).toEqual([expect.objectContaining({ kind: 'document', host: '10.255.255.1', attributed: true })]);
  });

  it('a later JS navigation of the main frame to a blocked host also yields 403', async () => {
    const seen = evilHits().length;
    const res = await renderReq({
      url: `${shop}/js-nav?url=${encodeURIComponent(`${evil}/product`)}`,
      wait_for_selector: '#never',
      timeout_ms: 3_000,
    });
    expect(res.statusCode).toBe(403);
    expect(res.json().error).toBe('blocked_address');
    expect(evilHits().length).toBe(seen);
  });
});
