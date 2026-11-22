import type { Browser, CDPSession } from 'playwright';
import type { NetPolicy } from './net-policy.js';

export type BlockKind = 'document' | 'subresource';

export interface BlockedRequest {
  /** `document` = the rendered page's main-frame navigation (incl. redirect hops). */
  readonly kind: BlockKind;
  readonly url: string;
  readonly host: string;
  readonly reason: string;
  /**
   * False when the guard could not tie the request to a tracked render (e.g. a
   * redirect inside an iframe or worker). Such events are delivered to the most
   * recently tracked listener so they are still counted in metrics.
   */
  readonly attributed: boolean;
}

export type BlockListener = (ev: BlockedRequest) => void;

interface Tracked {
  readonly policy: NetPolicy;
  readonly listener: BlockListener;
}

/**
 * Enforces the address policy on redirect hops.
 *
 * Playwright's context.route() is only invoked for the first request of a
 * redirect chain: Chromium pauses every hop, but Playwright auto-continues hops
 * without calling the route handler (navigations and subresources alike). So a
 * public URL that 302s to http://169.254.169.254/ would sail through. This
 * guard opens one browser-level CDP session per Chromium process with
 * `Fetch.enable` (which sees requests from every target - pages, OOPIFs,
 * workers), continues non-redirect requests untouched (context.route owns
 * those) and checks every redirect hop against the policy before Chromium
 * connects.
 */
export class RedirectGuard {
  private readonly frames = new Map<string, Tracked>();
  private fallback: Tracked | undefined;

  private constructor(private readonly session: CDPSession) {}

  static async attach(browser: Browser): Promise<RedirectGuard> {
    const session = await browser.newBrowserCDPSession();
    const guard = new RedirectGuard(session);
    session.on('Fetch.requestPaused', (e) => void guard.onPaused(e));
    await session.send('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }] });
    return guard;
  }

  /**
   * Route redirect hops of `frameId` (a page's main frame id == its CDP target
   * id) and of untracked frames through `policy`, reporting blocks to `listener`.
   * Returns an untrack function.
   */
  track(frameId: string, policy: NetPolicy, listener: BlockListener): () => void {
    const entry: Tracked = { policy, listener };
    this.frames.set(frameId, entry);
    this.fallback = entry;
    return () => {
      if (this.frames.get(frameId) === entry) this.frames.delete(frameId);
    };
  }

  private async onPaused(e: {
    requestId: string;
    request: { url: string };
    frameId: string;
    resourceType: string;
    redirectedRequestId?: string;
  }): Promise<void> {
    const requestId = e.requestId;
    try {
      const tracked = this.frames.get(e.frameId);
      const entry = tracked ?? this.fallback;
      if (!e.redirectedRequestId || !entry || !entry.policy.enabled) {
        await this.session.send('Fetch.continueRequest', { requestId });
        return;
      }
      const url = e.request.url;
      const scheme = url.slice(0, url.indexOf(':')).toLowerCase();
      if (scheme !== 'http' && scheme !== 'https') {
        await this.session.send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' });
        return;
      }
      const decision = await entry.policy.checkUrl(url);
      if (decision.action === 'allow') {
        await this.session.send('Fetch.continueRequest', { requestId });
      } else if (decision.action === 'unresolved') {
        await this.session.send('Fetch.failRequest', { requestId, errorReason: 'NameNotResolved' });
      } else {
        entry.listener({
          kind: tracked && e.resourceType === 'Document' ? 'document' : 'subresource',
          url,
          host: decision.host,
          reason: decision.reason,
          attributed: tracked !== undefined,
        });
        await this.session.send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' });
      }
    } catch {
      // Never leave a request paused; fail closed. (Errors here are mostly a
      // closed browser/session, where this is a no-op.)
      await this.session.send('Fetch.failRequest', { requestId, errorReason: 'Failed' }).catch(() => {});
    }
  }
}

const guards = new WeakMap<Browser, Promise<RedirectGuard>>();

/** The (lazily attached) guard for one Chromium process. */
export function redirectGuardFor(browser: Browser): Promise<RedirectGuard> {
  let guard = guards.get(browser);
  if (!guard) {
    guard = RedirectGuard.attach(browser);
    guards.set(browser, guard);
    guard.catch(() => {
      if (guards.get(browser) === guard) guards.delete(browser);
    });
  }
  return guard;
}
