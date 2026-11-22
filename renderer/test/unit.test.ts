import { EventEmitter } from 'node:events';
import type { Browser } from 'playwright';
import { describe, expect, it } from 'vitest';
import { BrowserPool } from '../src/browser-pool.js';
import { ConfigError, loadConfig } from '../src/config.js';
import { capHtml, validateTargetUrl } from '../src/render.js';

describe('loadConfig', () => {
  it('applies spec defaults', () => {
    expect(loadConfig({ RENDERER_TOKEN: 'secret' })).toMatchObject({
      port: 3100,
      rendererToken: 'secret',
      browserInstances: 4,
      contextsPerInstance: 4,
      defaultTimeoutMs: 20_000,
    });
  });

  it('refuses an empty RENDERER_TOKEN outside NODE_ENV=test', () => {
    expect(() => loadConfig({})).toThrow(ConfigError);
    expect(() => loadConfig({ RENDERER_TOKEN: '   ', NODE_ENV: 'production' })).toThrow(/RENDERER_TOKEN/);
    expect(loadConfig({ NODE_ENV: 'test' }).rendererToken).toBe('');
  });

  it('parses and bounds integers', () => {
    const c = loadConfig({ RENDERER_TOKEN: 't', PORT: '8080', BROWSER_INSTANCES: '2', CONTEXTS_PER_INSTANCE: '8' });
    expect(c).toMatchObject({ port: 8080, browserInstances: 2, contextsPerInstance: 8 });
    expect(() => loadConfig({ RENDERER_TOKEN: 't', BROWSER_INSTANCES: 'four' })).toThrow(ConfigError);
    expect(() => loadConfig({ RENDERER_TOKEN: 't', BROWSER_INSTANCES: '0' })).toThrow(ConfigError);
    expect(() => loadConfig({ RENDERER_TOKEN: 't', DEFAULT_TIMEOUT_MS: '-5' })).toThrow(ConfigError);
  });

  it('blocks private addresses by default with an empty allowlist', () => {
    expect(loadConfig({ RENDERER_TOKEN: 't' })).toMatchObject({ blockPrivate: true, allowHosts: [] });
  });

  it('parses RENDERER_BLOCK_PRIVATE', () => {
    expect(loadConfig({ NODE_ENV: 'test', RENDERER_BLOCK_PRIVATE: 'false' }).blockPrivate).toBe(false);
    expect(loadConfig({ NODE_ENV: 'development', RENDERER_TOKEN: 't', RENDERER_BLOCK_PRIVATE: '0' }).blockPrivate).toBe(false);
    expect(loadConfig({ RENDERER_TOKEN: 't', RENDERER_BLOCK_PRIVATE: 'TRUE' }).blockPrivate).toBe(true);
    expect(() => loadConfig({ RENDERER_TOKEN: 't', RENDERER_BLOCK_PRIVATE: 'maybe' })).toThrow(ConfigError);
  });

  it('refuses RENDERER_BLOCK_PRIVATE=false in production (the default NODE_ENV)', () => {
    expect(() => loadConfig({ RENDERER_TOKEN: 't', NODE_ENV: 'production', RENDERER_BLOCK_PRIVATE: 'false' })).toThrow(
      /RENDERER_BLOCK_PRIVATE=false is not allowed/,
    );
    expect(() => loadConfig({ RENDERER_TOKEN: 't', RENDERER_BLOCK_PRIVATE: 'false' })).toThrow(ConfigError);
  });

  it('parses and normalizes RENDERER_ALLOW_HOSTS', () => {
    const c = loadConfig({ RENDERER_TOKEN: 't', RENDERER_ALLOW_HOSTS: ' *.Fixture.test, 127.0.0.1 ,,[::1],localhost ' });
    expect(c.allowHosts).toEqual(['*.fixture.test', '127.0.0.1', '::1', 'localhost']);
    expect(() => loadConfig({ RENDERER_TOKEN: 't', RENDERER_ALLOW_HOSTS: 'http://x.test' })).toThrow(/RENDERER_ALLOW_HOSTS/);
    expect(() => loadConfig({ RENDERER_TOKEN: 't', RENDERER_ALLOW_HOSTS: '*' })).toThrow(ConfigError);
  });
});

describe('validateTargetUrl', () => {
  it('only allows http(s) with a host', () => {
    expect(validateTargetUrl('https://example.com/a')).toBeNull();
    expect(validateTargetUrl('http://127.0.0.1:8080/')).toBeNull();
    expect(validateTargetUrl('file:///etc/passwd')).toMatch(/scheme "file"/);
    expect(validateTargetUrl('data:text/html,x')).toMatch(/scheme "data"/);
    expect(validateTargetUrl('chrome://gpu')).toMatch(/scheme "chrome"/);
    expect(validateTargetUrl('nope')).toMatch(/absolute/);
  });
});

describe('capHtml', () => {
  it('passes small documents through untouched', () => {
    expect(capHtml('<p>hi</p>', 100)).toEqual({ html: '<p>hi</p>', truncated: false });
  });

  it('truncates on a UTF-8 code point boundary', () => {
    const html = 'ab€€'; // 2 + 3 + 3 bytes
    const out = capHtml(html, 6);
    expect(out).toEqual({ html: 'ab€', truncated: true });
    expect(Buffer.byteLength(out.html)).toBeLessThanOrEqual(6);
  });

  it('caps at 5 MB by default', () => {
    const out = capHtml('x'.repeat(5 * 1024 * 1024 + 10));
    expect(out.truncated).toBe(true);
    expect(out.html.length).toBe(5 * 1024 * 1024);
  });
});

class FakeBrowser extends EventEmitter {
  connected = true;
  isConnected() {
    return this.connected;
  }
  async close() {
    this.crash();
  }
  crash() {
    if (!this.connected) return;
    this.connected = false;
    this.emit('disconnected', this);
  }
}

function fakeLauncher() {
  const launched: FakeBrowser[] = [];
  const launch = async () => {
    const b = new FakeBrowser();
    launched.push(b);
    return b as unknown as Browser;
  };
  return { launched, launch };
}

describe('BrowserPool', () => {
  it('admits instances x contextsPerInstance leases, then returns null (no queueing)', async () => {
    const { launch, launched } = fakeLauncher();
    const pool = new BrowserPool({ instances: 2, contextsPerInstance: 2, launch, drainTimeoutMs: 50 });
    await pool.start();
    expect(launched).toHaveLength(2);

    const leases = [pool.acquire(), pool.acquire(), pool.acquire(), pool.acquire()];
    expect(leases.every((l) => l !== null)).toBe(true);
    // least-loaded balancing spreads leases across instances
    expect(leases.filter((l) => l!.instanceId === 0)).toHaveLength(2);
    expect(pool.utilization()).toBe(1);
    expect(pool.acquire()).toBeNull();

    leases[0]!.release();
    leases[0]!.release(); // idempotent
    expect(pool.active).toBe(3);
    expect(pool.acquire()).not.toBeNull();
    await pool.close();
  });

  it('relaunches a crashed instance', async () => {
    const { launch, launched } = fakeLauncher();
    const pool = new BrowserPool({ instances: 1, contextsPerInstance: 1, launch, relaunchBaseDelayMs: 10, drainTimeoutMs: 50 });
    await pool.start();
    launched[0]!.crash();
    expect(pool.readyInstances).toBe(0);
    expect(pool.acquire()).toBeNull();

    await expect.poll(() => launched.length, { timeout: 2000 }).toBe(2);
    expect(pool.readyInstances).toBe(1);
    expect(pool.acquire()?.browser).toBe(launched[1]);
    await pool.close();
  });

  it('retries failed relaunches with backoff', async () => {
    let calls = 0;
    const launch = async () => {
      calls++;
      if (calls === 2 || calls === 3) throw new Error('launch failed');
      return new FakeBrowser() as unknown as Browser;
    };
    const pool = new BrowserPool({ instances: 1, contextsPerInstance: 1, launch, relaunchBaseDelayMs: 5, drainTimeoutMs: 50 });
    await pool.start();
    (pool.acquire()!.browser as unknown as FakeBrowser).crash();
    await expect.poll(() => pool.readyInstances, { timeout: 2000 }).toBe(1);
    expect(calls).toBe(4);
    await pool.close();
  });

  it('does not relaunch after close() and refuses new leases', async () => {
    const { launch, launched } = fakeLauncher();
    const pool = new BrowserPool({ instances: 2, contextsPerInstance: 1, launch, relaunchBaseDelayMs: 1 });
    await pool.start();
    await pool.close();
    await new Promise((r) => setTimeout(r, 30));
    expect(launched).toHaveLength(2);
    expect(launched.every((b) => !b.connected)).toBe(true);
    expect(pool.acquire()).toBeNull();
  });

  it('close() waits for in-flight leases to drain', async () => {
    const { launch, launched } = fakeLauncher();
    const pool = new BrowserPool({ instances: 1, contextsPerInstance: 1, launch });
    await pool.start();
    const lease = pool.acquire()!;
    const closing = pool.close();
    await new Promise((r) => setTimeout(r, 100));
    expect(launched[0]!.connected).toBe(true);
    lease.release();
    await closing;
    expect(launched[0]!.connected).toBe(false);
  });

  it('start() rejects when Chromium cannot launch', async () => {
    const pool = new BrowserPool({
      instances: 1,
      contextsPerInstance: 1,
      launch: async () => {
        throw new Error('no chromium');
      },
    });
    await expect(pool.start()).rejects.toThrow('no chromium');
  });
});
