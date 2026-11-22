import { promises as dnsPromises } from 'node:dns';
import { BlockList, isIP } from 'node:net';

/**
 * Address policy for everything the browser fetches (spec §15 SSRF): a host is
 * blocked when its name is local-only or when ANY address it resolves to lies
 * in a private, loopback, link-local, shared, documentation, benchmarking,
 * multicast or otherwise reserved range.
 */

const IPV4_BLOCKED: ReadonlyArray<readonly [string, number]> = [
  ['0.0.0.0', 8],
  ['10.0.0.0', 8],
  ['100.64.0.0', 10],
  ['127.0.0.0', 8],
  ['169.254.0.0', 16],
  ['172.16.0.0', 12],
  ['192.0.0.0', 24],
  ['192.0.2.0', 24],
  ['192.168.0.0', 16],
  ['198.18.0.0', 15],
  ['198.51.100.0', 24],
  ['203.0.113.0', 24],
  ['224.0.0.0', 4],
  ['240.0.0.0', 4],
  ['255.255.255.255', 32],
];

const IPV6_BLOCKED: ReadonlyArray<readonly [string, number]> = [
  ['::', 128],
  ['::1', 128],
  ['64:ff9b::', 96],
  ['100::', 64],
  ['2001:db8::', 32],
  ['fc00::', 7],
  ['fe80::', 10],
  ['ff00::', 8],
];

interface Range {
  readonly cidr: string;
  readonly list: BlockList;
}

function buildRanges(entries: ReadonlyArray<readonly [string, number]>, family: 'ipv4' | 'ipv6'): Range[] {
  return entries.map(([net, prefix]) => {
    const list = new BlockList();
    list.addSubnet(net, prefix, family);
    return { cidr: `${net}/${prefix}`, list };
  });
}

const V4_RANGES = buildRanges(IPV4_BLOCKED, 'ipv4');
const V6_RANGES = buildRanges(IPV6_BLOCKED, 'ipv6');

/** Canonical (WHATWG) text form of an IPv6 address, or null if it is not one. */
function canonicalIPv6(addr: string): string | null {
  const bare = addr.replace(/^\[|\]$/g, '').replace(/%.*$/, ''); // brackets, zone id
  if (isIP(bare) !== 6) return null;
  try {
    return new URL(`http://[${bare}]/`).hostname.slice(1, -1);
  } catch {
    return null;
  }
}

const MAPPED_V4_RE = /^::ffff:([0-9a-f]{1,4}):([0-9a-f]{1,4})$/;

/**
 * Returns the blocked range (CIDR) an IP literal falls in, or null if it is a
 * public address. IPv4-mapped IPv6 (::ffff:a.b.c.d) is checked as IPv4.
 * Anything that is not an IP literal returns null.
 */
export function blockedRange(ip: string): string | null {
  if (isIP(ip) === 4) {
    return V4_RANGES.find((r) => r.list.check(ip, 'ipv4'))?.cidr ?? null;
  }
  const v6 = canonicalIPv6(ip);
  if (v6 === null) return null;
  const mapped = MAPPED_V4_RE.exec(v6);
  if (mapped) {
    const hi = parseInt(mapped[1]!, 16);
    const lo = parseInt(mapped[2]!, 16);
    const v4 = `${hi >> 8}.${hi & 0xff}.${lo >> 8}.${lo & 0xff}`;
    const r = blockedRange(v4);
    return r === null ? null : `::ffff:${r} (IPv4-mapped)`;
  }
  return V6_RANGES.find((r) => r.list.check(v6, 'ipv6'))?.cidr ?? null;
}

/** Lowercase, strip IPv6 brackets and trailing dots (`localhost.` == `localhost`). */
export function normalizeHost(host: string): string {
  const h = host.trim().toLowerCase().replace(/\.+$/, '');
  if (h.startsWith('[')) return canonicalIPv6(h) ?? h;
  return h;
}

const BLOCKED_NAME_SUFFIXES = ['localhost', 'local', 'internal'] as const;

/** Local-only hostnames (`localhost`, `*.localhost`, `*.local`, `*.internal`). */
export function blockedHostname(host: string): string | null {
  const h = normalizeHost(host);
  for (const suffix of BLOCKED_NAME_SUFFIXES) {
    if (h === suffix) return suffix;
    if (h.endsWith(`.${suffix}`)) return `*.${suffix}`;
  }
  return null;
}

/**
 * Validate and normalize one RENDERER_ALLOW_HOSTS entry: an exact host / IP
 * literal (`127.0.0.1`, `[::1]`, `shop.fixture.test`) or a `*.suffix`
 * wildcard, which matches subdomains of `suffix` (not `suffix` itself).
 */
export function parseHostPattern(raw: string): string {
  const entry = raw.trim().toLowerCase();
  const wildcard = entry.startsWith('*.');
  const body = wildcard ? entry.slice(2) : entry;
  if (body === '') throw new Error(`empty host pattern "${raw}"`);

  const v6 = canonicalIPv6(body);
  if (v6 !== null) {
    if (wildcard) throw new Error(`wildcards are not allowed on IP literals: "${raw}"`);
    return v6;
  }
  if (/[\s/?#@:*\\[\]]/.test(body) || body.includes('..')) {
    throw new Error(`invalid host pattern "${raw}" (expected a host, IP, or *.suffix)`);
  }
  let host: string;
  try {
    host = new URL(`http://${body}/`).hostname; // punycode + canonical IPv4
  } catch {
    throw new Error(`invalid host pattern "${raw}"`);
  }
  host = host.replace(/\.+$/, '');
  if (host === '') throw new Error(`invalid host pattern "${raw}"`);
  return wildcard ? `*.${host}` : host;
}

export function matchesHostPattern(host: string, pattern: string): boolean {
  const h = normalizeHost(host);
  if (pattern.startsWith('*.')) return h.endsWith(pattern.slice(1));
  return h === pattern;
}

export type Resolver = (hostname: string) => Promise<string[]>;

/** Default resolver: the system resolver, every address, in resolver order. */
export const systemResolver: Resolver = async (hostname) =>
  (await dnsPromises.lookup(hostname, { all: true, verbatim: true })).map((a) => a.address);

export type PolicyDecision =
  | { readonly action: 'allow' }
  | { readonly action: 'block'; readonly host: string; readonly reason: string }
  /** DNS failed: the caller fails the request as unresolvable (fail closed, but retryable). */
  | { readonly action: 'unresolved'; readonly host: string; readonly reason: string };

const ALLOW: PolicyDecision = { action: 'allow' };

export interface NetPolicyOptions {
  /** Master switch (RENDERER_BLOCK_PRIVATE). When false every request is allowed. */
  blockPrivate: boolean;
  /** Normalized patterns (see parseHostPattern) that bypass the policy entirely. */
  allowHosts?: readonly string[];
  resolver?: Resolver;
  /** How long successful lookups are cached (default 30 s). Failures are not cached. */
  cacheTtlMs?: number;
  maxCacheEntries?: number;
  now?: () => number;
}

interface CacheEntry {
  readonly expires: number;
  readonly addresses: Promise<string[]>;
}

export class NetPolicy {
  readonly enabled: boolean;
  readonly allowHosts: readonly string[];
  private readonly resolver: Resolver;
  private readonly ttlMs: number;
  private readonly maxEntries: number;
  private readonly now: () => number;
  private readonly cache = new Map<string, CacheEntry>();

  constructor(opts: NetPolicyOptions) {
    this.enabled = opts.blockPrivate;
    this.allowHosts = (opts.allowHosts ?? []).map(parseHostPattern);
    this.resolver = opts.resolver ?? systemResolver;
    this.ttlMs = opts.cacheTtlMs ?? 30_000;
    this.maxEntries = opts.maxCacheEntries ?? 5_000;
    this.now = opts.now ?? Date.now;
  }

  isAllowlisted(host: string): boolean {
    return this.allowHosts.some((p) => matchesHostPattern(host, p));
  }

  /**
   * Decide on a URL. data:/blob: are allowed; http(s)/ws(s) are checked by
   * host; any other scheme is blocked.
   */
  async checkUrl(rawUrl: string): Promise<PolicyDecision> {
    let url: URL;
    try {
      url = new URL(rawUrl);
    } catch {
      return { action: 'block', host: '', reason: 'unparseable URL' };
    }
    switch (url.protocol) {
      case 'data:':
      case 'blob:':
        return ALLOW;
      case 'http:':
      case 'https:':
      case 'ws:':
      case 'wss:':
        return this.checkHost(url.hostname);
      default:
        return { action: 'block', host: url.hostname, reason: `scheme "${url.protocol.slice(0, -1)}" is not allowed` };
    }
  }

  async checkHost(rawHost: string): Promise<PolicyDecision> {
    if (!this.enabled) return ALLOW;
    const host = normalizeHost(rawHost);
    if (host === '') return { action: 'block', host, reason: 'empty host' };
    if (this.isAllowlisted(host)) return ALLOW;

    const name = blockedHostname(host);
    if (name) return { action: 'block', host, reason: `hostname matches ${name}` };

    if (isIP(host) !== 0) {
      const range = blockedRange(host);
      return range ? { action: 'block', host, reason: `address ${host} is in blocked range ${range}` } : ALLOW;
    }

    let addresses: string[];
    try {
      addresses = await this.lookup(host);
    } catch (err) {
      const code = (err as NodeJS.ErrnoException)?.code ?? (err instanceof Error ? err.message : String(err));
      return { action: 'unresolved', host, reason: `DNS lookup failed (${code})` };
    }
    if (addresses.length === 0) return { action: 'unresolved', host, reason: 'DNS lookup returned no addresses' };
    for (const addr of addresses) {
      const range = blockedRange(addr);
      if (range) return { action: 'block', host, reason: `${host} resolves to ${addr} (blocked range ${range})` };
    }
    return ALLOW;
  }

  /** Cached, de-duplicated lookup. Rejected lookups are evicted immediately. */
  private lookup(host: string): Promise<string[]> {
    const now = this.now();
    const hit = this.cache.get(host);
    if (hit && hit.expires > now) return hit.addresses;
    if (hit) this.cache.delete(host);

    const addresses = this.resolver(host);
    this.cache.set(host, { expires: now + this.ttlMs, addresses });
    addresses.catch(() => {
      if (this.cache.get(host)?.addresses === addresses) this.cache.delete(host);
    });
    while (this.cache.size > this.maxEntries) {
      const oldest = this.cache.keys().next().value;
      if (oldest === undefined) break;
      this.cache.delete(oldest);
    }
    return addresses;
  }
}

/** Thrown by render() when the main document (or a redirect hop of it) is blocked. */
export class BlockedAddressError extends Error {
  override readonly name = 'BlockedAddressError';
  constructor(
    readonly host: string,
    readonly reason: string,
  ) {
    super(`request to ${host || 'target'} blocked by address policy: ${reason}`);
  }
}
