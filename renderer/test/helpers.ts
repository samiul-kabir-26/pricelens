import { Writable } from 'node:stream';
import type { Browser } from 'playwright';
import type { Lease, RenderPool } from '../src/browser-pool.js';
import type { Config } from '../src/config.js';
import { NetPolicy, type NetPolicyOptions } from '../src/net-policy.js';

export const TOKEN = 'test-token-0123456789';

export function testConfig(overrides: Partial<Config> = {}): Config {
  return {
    port: 0,
    host: '127.0.0.1',
    rendererToken: TOKEN,
    browserInstances: 1,
    contextsPerInstance: 1,
    defaultTimeoutMs: 20_000,
    logLevel: 'silent',
    nodeEnv: 'test',
    blockPrivate: true,
    allowHosts: [],
    ...overrides,
  };
}

/** In-memory pool that hands out a dummy browser; capacity is configurable. */
export class FakePool implements RenderPool {
  active = 0;
  released = 0;
  closed = false;
  constructor(public capacity = 2) {}
  get readyInstances() {
    return 1;
  }
  acquire(): Lease | null {
    if (this.active >= this.capacity) return null;
    this.active++;
    let done = false;
    return {
      browser: {} as Browser,
      instanceId: 0,
      release: () => {
        if (done) return;
        done = true;
        this.active--;
        this.released++;
      },
    };
  }
  utilization() {
    return this.active / this.capacity;
  }
  async close() {
    this.closed = true;
  }
}

/** Writable that collects pino JSON lines. */
export function logCapture(): { stream: Writable; lines: () => Array<Record<string, unknown>> } {
  const chunks: string[] = [];
  const stream = new Writable({
    write(chunk: Buffer, _enc, cb) {
      chunks.push(chunk.toString('utf8'));
      cb();
    },
  });
  return {
    stream,
    lines: () =>
      chunks
        .join('')
        .split('\n')
        .filter(Boolean)
        .map((l) => JSON.parse(l) as Record<string, unknown>),
  };
}

/** Public address returned by stubResolver for unknown names (TEST-NET-free, routable). */
export const PUBLIC_IP = '93.184.215.14';

/**
 * Deterministic resolver: names in `map` resolve to the given addresses; names
 * listed with an empty array fail (ENOTFOUND); anything else resolves to PUBLIC_IP.
 */
export function stubResolver(map: Record<string, string[]> = {}) {
  const calls: string[] = [];
  const resolve = async (host: string): Promise<string[]> => {
    calls.push(host);
    const hit = map[host];
    if (hit === undefined) return [PUBLIC_IP];
    if (hit.length === 0) throw Object.assign(new Error(`getaddrinfo ENOTFOUND ${host}`), { code: 'ENOTFOUND' });
    return hit;
  };
  return { resolve, calls };
}

/** A blocking NetPolicy that never touches real DNS. */
export function stubPolicy(map: Record<string, string[]> = {}, opts: Partial<NetPolicyOptions> = {}): NetPolicy {
  return new NetPolicy({ blockPrivate: true, resolver: stubResolver(map).resolve, ...opts });
}
