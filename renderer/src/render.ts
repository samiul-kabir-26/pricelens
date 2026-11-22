import { errors, type Browser, type BrowserContext, type BrowserContextOptions, type Page, type Request } from 'playwright';
import { MAX_TIMEOUT_MS, MIN_TIMEOUT_MS } from './config.js';
import { BlockedAddressError, type NetPolicy } from './net-policy.js';
import { redirectGuardFor, type BlockListener, type BlockedRequest } from './redirect-guard.js';

export const WAIT_UNTIL = ['load', 'domcontentloaded', 'networkidle', 'commit'] as const;
export type WaitUntil = (typeof WAIT_UNTIL)[number];

/** Playwright resource types that may be blocked (the main `document` never is). */
export const BLOCKABLE_RESOURCES = [
  'stylesheet',
  'image',
  'media',
  'font',
  'script',
  'texttrack',
  'xhr',
  'fetch',
  'eventsource',
  'websocket',
  'manifest',
  'other',
] as const;
export type BlockableResource = (typeof BLOCKABLE_RESOURCES)[number];

export const MAX_HTML_BYTES = 5 * 1024 * 1024;

export interface RenderRequest {
  url: string;
  wait_until?: WaitUntil;
  wait_for_selector?: string;
  timeout_ms?: number;
  proxy?: { server: string; username?: string; password?: string };
  block_resources?: BlockableResource[];
  viewport?: { width: number; height: number };
  locale?: string;
  screenshot?: boolean;
}

export interface RenderResult {
  final_url: string;
  status: number | null;
  html: string;
  timing_ms: number;
  screenshot_base64: string | null;
  /** Not part of the wire response; surfaced as the X-Html-Truncated header. */
  truncated: boolean;
}

export interface RenderOptions {
  defaultTimeoutMs: number;
  maxHtmlBytes?: number;
  /** Address (SSRF) policy. When absent or disabled, no address checks are made. */
  netPolicy?: NetPolicy;
  /** Called for every request the address policy blocks on behalf of this render. */
  onBlocked?: BlockListener;
}

export type RenderFn = (browser: Browser, req: RenderRequest, opts: RenderOptions) => Promise<RenderResult>;

/** JSON schema for POST /render (validated by Fastify's built-in Ajv). */
export const renderRequestSchema = {
  type: 'object',
  additionalProperties: false,
  required: ['url'],
  properties: {
    url: { type: 'string', minLength: 1, maxLength: 4096 },
    wait_until: { type: 'string', enum: [...WAIT_UNTIL] },
    wait_for_selector: { type: 'string', minLength: 1, maxLength: 1024 },
    timeout_ms: { type: 'integer', minimum: MIN_TIMEOUT_MS, maximum: MAX_TIMEOUT_MS },
    proxy: {
      type: 'object',
      additionalProperties: false,
      required: ['server'],
      properties: {
        server: { type: 'string', minLength: 1, maxLength: 512, pattern: '^(https?|socks5)://' },
        username: { type: 'string', maxLength: 512 },
        password: { type: 'string', maxLength: 512 },
      },
    },
    block_resources: {
      type: 'array',
      uniqueItems: true,
      maxItems: BLOCKABLE_RESOURCES.length,
      items: { type: 'string', enum: [...BLOCKABLE_RESOURCES] },
    },
    viewport: {
      type: 'object',
      additionalProperties: false,
      required: ['width', 'height'],
      properties: {
        width: { type: 'integer', minimum: 320, maximum: 3840 },
        height: { type: 'integer', minimum: 240, maximum: 4320 },
      },
    },
    locale: { type: 'string', maxLength: 35, pattern: '^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$' },
    screenshot: { type: 'boolean' },
  },
} as const;

/**
 * Only absolute http(s) URLs with a host are renderable. Returns an error
 * message, or null when the URL is acceptable (file:, data:, chrome:,
 * javascript:, about:, ftp:, ... are all rejected).
 */
export function validateTargetUrl(raw: string): string | null {
  let parsed: URL;
  try {
    parsed = new URL(raw);
  } catch {
    return 'url must be an absolute URL';
  }
  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
    return `url scheme "${parsed.protocol.replace(/:$/, '')}" is not allowed; only http and https are supported`;
  }
  if (parsed.hostname === '') return 'url must include a host';
  return null;
}

/** Truncate to at most maxBytes of UTF-8 without splitting a code point. */
export function capHtml(html: string, maxBytes: number = MAX_HTML_BYTES): { html: string; truncated: boolean } {
  const buf = Buffer.from(html, 'utf8');
  if (buf.byteLength <= maxBytes) return { html, truncated: false };
  let end = maxBytes;
  // Step back over UTF-8 continuation bytes (10xxxxxx) to a code-point boundary.
  while (end > 0 && ((buf[end] ?? 0) & 0xc0) === 0x80) end--;
  return { html: buf.subarray(0, end).toString('utf8'), truncated: true };
}

export function isTimeoutError(err: unknown): boolean {
  return err instanceof errors.TimeoutError;
}

/** First line of a Playwright error (drops the multi-line call log). */
export function summarizeError(err: unknown): string {
  const msg = err instanceof Error ? err.message : String(err);
  const first = msg.split('\n', 1)[0]?.trim() ?? '';
  return (first || 'navigation failed').slice(0, 500);
}

function remaining(deadline: number): number {
  const left = deadline - Date.now();
  if (left <= 0) throw new errors.TimeoutError(`Render timeout exceeded`);
  return left;
}

/** True for navigations of the rendered page's main frame (not iframes/popups). */
function isMainDocument(request: Request, page: Page | undefined): boolean {
  try {
    return page !== undefined && request.isNavigationRequest() && request.frame() === page.mainFrame();
  } catch {
    return false; // service-worker requests have no frame
  }
}

/** Render one URL in a fresh, isolated BrowserContext (always closed). */
export const render: RenderFn = async (browser, req, opts) => {
  const started = Date.now();
  const timeout = req.timeout_ms ?? opts.defaultTimeoutMs;
  const deadline = started + timeout;
  const blocked = new Set<string>(req.block_resources ?? []);
  const policy = opts.netPolicy?.enabled ? opts.netPolicy : undefined;

  // First blocked main-document request (initial navigation, redirect hop or a
  // later JS navigation of the main frame). Turns the render into a 403.
  let documentBlock: BlockedRequest | undefined;
  const reportBlocked: BlockListener = (ev) => {
    if (ev.kind === 'document' && ev.attributed) documentBlock ??= ev;
    opts.onBlocked?.(ev);
  };

  const contextOptions: BrowserContextOptions = {
    acceptDownloads: false,
    serviceWorkers: 'block', // service workers would bypass route-based blocking
    javaScriptEnabled: true,
    ...(req.viewport ? { viewport: req.viewport } : {}),
    ...(req.locale ? { locale: req.locale } : {}),
    ...(req.proxy ? { proxy: { ...req.proxy } } : {}),
  };

  let context: BrowserContext | undefined;
  let untrack: (() => void) | undefined;
  try {
    // Redirect hops never reach context.route(); the per-browser guard covers them.
    const guard = policy ? await redirectGuardFor(browser) : undefined;

    context = await browser.newContext(contextOptions);
    context.setDefaultTimeout(timeout);
    context.setDefaultNavigationTimeout(timeout);

    let page: Page | undefined;
    await context.route('**/*', async (route) => {
      const request = route.request();
      const url = request.url();
      const scheme = url.slice(0, url.indexOf(':')).toLowerCase();
      const allowedScheme = scheme === 'http' || scheme === 'https' || scheme === 'data' || scheme === 'blob';
      try {
        if (!allowedScheme || blocked.has(request.resourceType())) {
          await route.abort('blockedbyclient');
          return;
        }
        if (policy && (scheme === 'http' || scheme === 'https')) {
          const decision = await policy.checkUrl(url);
          if (decision.action === 'unresolved') {
            await route.abort('namenotresolved');
            return;
          }
          if (decision.action === 'block') {
            reportBlocked({
              kind: isMainDocument(request, page) ? 'document' : 'subresource',
              url,
              host: decision.host,
              reason: decision.reason,
              attributed: true,
            });
            await route.abort('blockedbyclient');
            return;
          }
        }
        await route.continue();
      } catch {
        // page/context already closed - nothing to do
      }
    });

    if (blocked.has('websocket')) {
      await context.routeWebSocket(/.*/, (ws) => ws.close());
    } else if (policy) {
      // WebSocket handshakes bypass context.route and CDP Fetch; routeWebSocket
      // (an in-page shim) is the only hook. Allowed sockets connect from the page.
      await context.routeWebSocket(/.*/, async (ws) => {
        const decision = await policy.checkUrl(ws.url());
        if (decision.action === 'allow') {
          ws.connectToServer();
          return;
        }
        if (decision.action === 'block') {
          reportBlocked({ kind: 'subresource', url: ws.url(), host: decision.host, reason: decision.reason, attributed: true });
        }
        await ws.close({ code: 1008, reason: 'blocked' });
      });
    }

    // Popups and dialogs must never stall a render.
    context.on('page', (p) => {
      p.on('dialog', (d) => void d.dismiss().catch(() => {}));
    });

    page = await context.newPage();
    if (guard && policy) {
      // A page's main-frame id equals its CDP target id.
      const cdp = await context.newCDPSession(page);
      try {
        const { targetInfo } = await cdp.send('Target.getTargetInfo');
        untrack = guard.track(targetInfo.targetId, policy, reportBlocked);
      } finally {
        await cdp.detach().catch(() => {});
      }
    }

    let result: RenderResult;
    try {
      const response = await page.goto(req.url, {
        waitUntil: req.wait_until ?? 'load',
        timeout: remaining(deadline),
      });

      if (req.wait_for_selector) {
        await page.waitForSelector(req.wait_for_selector, {
          state: 'attached',
          timeout: remaining(deadline),
        });
      }

      const { html, truncated } = capHtml(await page.content(), opts.maxHtmlBytes ?? MAX_HTML_BYTES);
      const screenshot = req.screenshot
        ? (await page.screenshot({ type: 'png', timeout: Math.max(remaining(deadline), 1_000) })).toString('base64')
        : null;

      result = {
        final_url: page.url(),
        status: response?.status() ?? null,
        html,
        timing_ms: Date.now() - started,
        screenshot_base64: screenshot,
        truncated,
      };
    } catch (err) {
      if (documentBlock) throw new BlockedAddressError(documentBlock.host, documentBlock.reason);
      throw err;
    }
    if (documentBlock) throw new BlockedAddressError(documentBlock.host, documentBlock.reason);
    return result;
  } finally {
    untrack?.();
    if (context) await context.close().catch(() => {});
  }
};
