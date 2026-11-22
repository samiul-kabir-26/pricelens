import { chromium, type Browser } from 'playwright';

export type Launcher = () => Promise<Browser>;

/** Minimal logger surface (satisfied by pino / Fastify's logger). */
export interface PoolLogger {
  info(obj: object, msg?: string): void;
  warn(obj: object, msg?: string): void;
  error(obj: object, msg?: string): void;
}

/** A reserved context slot on one Chromium instance. Always call release(). */
export interface Lease {
  readonly browser: Browser;
  readonly instanceId: number;
  release(): void;
}

/** What the HTTP layer needs from a pool (lets tests inject a fake). */
export interface RenderPool {
  acquire(): Lease | null;
  readonly capacity: number;
  readonly active: number;
  readonly readyInstances: number;
  utilization(): number;
  close(): Promise<void>;
}

export interface BrowserPoolOptions {
  instances: number;
  contextsPerInstance: number;
  launch?: Launcher;
  logger?: PoolLogger;
  /** First relaunch delay after a crash; doubles per consecutive failure (max 10 s). */
  relaunchBaseDelayMs?: number;
  /** How long close() waits for in-flight renders before killing browsers. */
  drainTimeoutMs?: number;
}

interface Instance {
  readonly id: number;
  browser: Browser | null;
  active: number;
  failures: number;
  relaunchTimer: NodeJS.Timeout | undefined;
}

const MAX_RELAUNCH_DELAY_MS = 10_000;

const silentLogger: PoolLogger = { info() {}, warn() {}, error() {} };

export const defaultLauncher: Launcher = () =>
  chromium.launch({
    headless: true,
    args: ['--disable-dev-shm-usage', '--disable-gpu', '--no-first-run', '--disable-extensions'],
  });

/**
 * Fixed-size pool of Chromium processes. Each process admits at most
 * `contextsPerInstance` concurrent leases; acquire() never queues - it returns
 * null when every slot is busy so the caller can answer 503 immediately.
 */
export class BrowserPool implements RenderPool {
  logger: PoolLogger;
  private readonly instances: Instance[];
  private readonly perInstance: number;
  private readonly launch: Launcher;
  private readonly relaunchBaseDelayMs: number;
  private readonly drainTimeoutMs: number;
  private closing = false;

  constructor(opts: BrowserPoolOptions) {
    this.perInstance = opts.contextsPerInstance;
    this.launch = opts.launch ?? defaultLauncher;
    this.logger = opts.logger ?? silentLogger;
    this.relaunchBaseDelayMs = opts.relaunchBaseDelayMs ?? 500;
    this.drainTimeoutMs = opts.drainTimeoutMs ?? 15_000;
    this.instances = Array.from({ length: opts.instances }, (_, id) => ({
      id,
      browser: null,
      active: 0,
      failures: 0,
      relaunchTimer: undefined,
    }));
  }

  /** Launch every instance. Rejects (and cleans up) if any launch fails. */
  async start(): Promise<void> {
    const results = await Promise.allSettled(this.instances.map((i) => this.launchInto(i)));
    const failed = results.find((r): r is PromiseRejectedResult => r.status === 'rejected');
    if (failed) {
      await this.close();
      throw failed.reason;
    }
    this.logger.info({ instances: this.instances.length, contextsPerInstance: this.perInstance }, 'browser pool started');
  }

  acquire(): Lease | null {
    if (this.closing) return null;
    let best: Instance | undefined;
    for (const inst of this.instances) {
      if (!inst.browser || !inst.browser.isConnected() || inst.active >= this.perInstance) continue;
      if (!best || inst.active < best.active) best = inst;
    }
    if (!best || !best.browser) return null;

    const inst = best;
    const browser = best.browser;
    inst.active++;
    let released = false;
    return {
      browser,
      instanceId: inst.id,
      release: () => {
        if (released) return;
        released = true;
        inst.active--;
      },
    };
  }

  get capacity(): number {
    return this.instances.length * this.perInstance;
  }

  get active(): number {
    return this.instances.reduce((n, i) => n + i.active, 0);
  }

  get readyInstances(): number {
    return this.instances.filter((i) => i.browser?.isConnected()).length;
  }

  utilization(): number {
    return this.capacity === 0 ? 0 : this.active / this.capacity;
  }

  async close(): Promise<void> {
    this.closing = true;
    for (const inst of this.instances) clearTimeout(inst.relaunchTimer);

    const deadline = Date.now() + this.drainTimeoutMs;
    while (this.active > 0 && Date.now() < deadline) {
      await new Promise((r) => setTimeout(r, 50));
    }
    if (this.active > 0) {
      this.logger.warn({ active: this.active }, 'drain timeout reached; closing browsers with renders in flight');
    }

    await Promise.allSettled(
      this.instances.map(async (inst) => {
        const b = inst.browser;
        inst.browser = null;
        if (b) await b.close();
      }),
    );
  }

  private async launchInto(inst: Instance): Promise<void> {
    const browser = await this.launch();
    if (this.closing) {
      await browser.close().catch(() => {});
      return;
    }
    inst.browser = browser;
    inst.failures = 0;
    browser.on('disconnected', () => this.onDisconnected(inst, browser));
  }

  private onDisconnected(inst: Instance, browser: Browser): void {
    if (inst.browser !== browser) return;
    inst.browser = null;
    if (this.closing) return;
    this.logger.warn({ instance: inst.id }, 'chromium instance disconnected; relaunching');
    this.scheduleRelaunch(inst);
  }

  private scheduleRelaunch(inst: Instance): void {
    const delay = Math.min(this.relaunchBaseDelayMs * 2 ** inst.failures, MAX_RELAUNCH_DELAY_MS);
    inst.relaunchTimer = setTimeout(() => {
      inst.relaunchTimer = undefined;
      if (this.closing) return;
      this.launchInto(inst).then(
        () => {
          if (inst.browser) this.logger.info({ instance: inst.id }, 'chromium instance relaunched');
        },
        (err: unknown) => {
          inst.failures++;
          this.logger.error({ instance: inst.id, err }, 'chromium relaunch failed');
          if (!this.closing) this.scheduleRelaunch(inst);
        },
      );
    }, delay);
  }
}
