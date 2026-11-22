import { existsSync } from 'node:fs';
import { createServer, type AddressInfo } from 'node:net';
import type { FastifyInstance } from 'fastify';
import { chromium } from 'playwright';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { BrowserPool } from '../src/browser-pool.js';
import { buildApp } from '../src/server.js';
import { startFixtureServer, type FixtureServer } from './fixtures/server.js';
import { TOKEN, testConfig } from './helpers.js';

function chromiumInstalled(): boolean {
  try {
    return existsSync(chromium.executablePath());
  } catch {
    return false;
  }
}

/** A localhost port with nothing listening on it. */
async function closedPort(): Promise<number> {
  const srv = createServer();
  await new Promise<void>((r) => srv.listen(0, '127.0.0.1', r));
  const { port } = srv.address() as AddressInfo;
  await new Promise<void>((r) => srv.close(() => r()));
  return port;
}

const hasChromium = chromiumInstalled();
if (!hasChromium) {
  console.warn('[renderer tests] Chromium not installed - skipping integration tests (run `npx playwright install chromium`)');
}

describe.skipIf(!hasChromium)('POST /render against a real Chromium', () => {
  let fixtures: FixtureServer;
  let pool: BrowserPool;
  let app: FastifyInstance;

  beforeAll(async () => {
    fixtures = await startFixtureServer();
    // One instance, one context slot: makes saturation deterministic.
    pool = new BrowserPool({ instances: 1, contextsPerInstance: 1 });
    await pool.start();
    // The fixtures live on loopback, so allowlist it (as RENDERER_ALLOW_HOSTS=127.0.0.1,localhost would).
    app = buildApp({
      config: testConfig({ defaultTimeoutMs: 10_000, blockPrivate: true, allowHosts: ['127.0.0.1', 'localhost'] }),
      pool,
      logger: false,
    });
  });

  afterAll(async () => {
    await app?.close(); // also closes the pool
    await fixtures?.close();
  });

  const renderReq = (body: Record<string, unknown>) =>
    app.inject({
      method: 'POST',
      url: '/render',
      headers: { authorization: `Bearer ${TOKEN}` },
      payload: body,
    });

  it('renders a static product page', async () => {
    const res = await renderReq({ url: `${fixtures.origin}/product` });
    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(Object.keys(body).sort()).toEqual(['final_url', 'html', 'screenshot_base64', 'status', 'timing_ms']);
    expect(body.status).toBe(200);
    expect(body.final_url).toBe(`${fixtures.origin}/product`);
    expect(body.html).toContain('$19.99');
    expect(body.screenshot_base64).toBeNull();
    expect(body.timing_ms).toBeGreaterThan(0);
  });

  it('reports the final URL after redirects', async () => {
    const res = await renderReq({ url: `${fixtures.origin}/redirect` });
    expect(res.statusCode).toBe(200);
    expect(res.json().final_url).toBe(`${fixtures.origin}/product`);
  });

  it('captures a price injected by JS after a setTimeout (networkidle)', async () => {
    const res = await renderReq({ url: `${fixtures.origin}/spa`, wait_until: 'networkidle' });
    expect(res.statusCode).toBe(200);
    expect(res.json().html).toContain('<span data-testid="price">$42.00</span>');
  });

  it('wait_for_selector waits for late client-side content', async () => {
    // Without the selector the late price is not there yet...
    const early = await renderReq({ url: `${fixtures.origin}/spa-slow`, wait_until: 'load' });
    expect(early.statusCode).toBe(200);
    expect(early.json().html).not.toContain('$77.50');

    // ...with it, the render waits until it is.
    const res = await renderReq({
      url: `${fixtures.origin}/spa-slow`,
      wait_until: 'load',
      wait_for_selector: '[data-testid=price]',
      viewport: { width: 1366, height: 900 },
      locale: 'en-US',
    });
    expect(res.statusCode).toBe(200);
    expect(res.json().html).toContain('$77.50');
  });

  it('returns 504 when a selector never appears', async () => {
    const res = await renderReq({
      url: `${fixtures.origin}/product`,
      wait_for_selector: '#does-not-exist',
      timeout_ms: 1500,
    });
    expect(res.statusCode).toBe(504);
    expect(res.json()).toEqual({ error: 'timeout' });
  });

  it('returns 504 for a page that never finishes loading', async () => {
    const started = Date.now();
    const res = await renderReq({ url: `${fixtures.origin}/hang`, timeout_ms: 1500 });
    expect(res.statusCode).toBe(504);
    expect(res.json()).toEqual({ error: 'timeout' });
    expect(Date.now() - started).toBeLessThan(8_000);
    expect(pool.active).toBe(0);
  });

  it('blocks requested resource types (images)', async () => {
    const before = fixtures.imageHits();
    const blocked = await renderReq({ url: `${fixtures.origin}/product`, block_resources: ['image', 'font', 'media'] });
    expect(blocked.statusCode).toBe(200);
    expect(blocked.json().html).toContain('id="hero"');
    expect(fixtures.imageHits()).toBe(before);

    const unblocked = await renderReq({ url: `${fixtures.origin}/product` });
    expect(unblocked.statusCode).toBe(200);
    expect(fixtures.imageHits()).toBe(before + 1);
  });

  it('returns a PNG screenshot when asked', async () => {
    const res = await renderReq({ url: `${fixtures.origin}/product`, screenshot: true, block_resources: ['image'] });
    expect(res.statusCode).toBe(200);
    const png = Buffer.from(res.json().screenshot_base64 as string, 'base64');
    expect(png.subarray(1, 4).toString('ascii')).toBe('PNG');
  });

  it('returns 502 navigation_failed for unreachable hosts', async () => {
    const port = await closedPort();
    const res = await renderReq({ url: `http://127.0.0.1:${port}/`, timeout_ms: 5000 });
    expect(res.statusCode).toBe(502);
    expect(res.json()).toMatchObject({ error: 'navigation_failed' });
    expect(res.json().message).toMatch(/net::ERR_CONNECTION_REFUSED/);
  });

  it('returns 503 saturated immediately while every slot is busy', async () => {
    const hanging = renderReq({ url: `${fixtures.origin}/hang`, timeout_ms: 2000 });
    await expect.poll(() => pool.active, { timeout: 2000 }).toBe(1);

    const started = Date.now();
    const res = await renderReq({ url: `${fixtures.origin}/product` });
    expect(res.statusCode).toBe(503);
    expect(res.headers['retry-after']).toBe('2');
    expect(res.json()).toEqual({ error: 'saturated' });
    expect(Date.now() - started).toBeLessThan(500);

    expect((await hanging).statusCode).toBe(504);
  });

  it('does not leak cookies between requests (fresh context)', async () => {
    // Each render gets a new BrowserContext; verify none linger after a render.
    await renderReq({ url: `${fixtures.origin}/product` });
    const lease = pool.acquire()!;
    try {
      expect(lease.browser.contexts()).toHaveLength(0);
    } finally {
      lease.release();
    }
  });

  it('relaunches a crashed Chromium instance', async () => {
    const lease = pool.acquire()!;
    const crashed = lease.browser;
    lease.release();
    await crashed.close(); // simulates the process going away
    expect(pool.readyInstances).toBe(0);
    await expect.poll(() => pool.readyInstances, { timeout: 15_000, interval: 100 }).toBe(1);

    const res = await renderReq({ url: `${fixtures.origin}/product` });
    expect(res.statusCode).toBe(200);
    expect(res.json().html).toContain('$19.99');
  });
});
