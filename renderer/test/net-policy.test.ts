import { describe, expect, it } from 'vitest';
import {
  NetPolicy,
  blockedHostname,
  blockedRange,
  matchesHostPattern,
  normalizeHost,
  parseHostPattern,
} from '../src/net-policy.js';
import { PUBLIC_IP, stubResolver } from './helpers.js';

describe('blockedRange (IPv4)', () => {
  it.each([
    ['0.0.0.0', '0.0.0.0/8'],
    ['0.255.1.2', '0.0.0.0/8'],
    ['10.0.0.1', '10.0.0.0/8'],
    ['10.255.255.255', '10.0.0.0/8'],
    ['100.64.0.1', '100.64.0.0/10'],
    ['100.127.255.255', '100.64.0.0/10'],
    ['127.0.0.1', '127.0.0.0/8'],
    ['127.255.0.9', '127.0.0.0/8'],
    ['169.254.169.254', '169.254.0.0/16'],
    ['172.16.0.1', '172.16.0.0/12'],
    ['172.31.255.255', '172.16.0.0/12'],
    ['192.0.0.8', '192.0.0.0/24'],
    ['192.0.2.1', '192.0.2.0/24'],
    ['192.168.1.1', '192.168.0.0/16'],
    ['198.18.0.1', '198.18.0.0/15'],
    ['198.19.255.255', '198.18.0.0/15'],
    ['198.51.100.7', '198.51.100.0/24'],
    ['203.0.113.9', '203.0.113.0/24'],
    ['224.0.0.251', '224.0.0.0/4'],
    ['239.255.255.250', '224.0.0.0/4'],
    ['240.0.0.1', '240.0.0.0/4'],
    ['255.255.255.255', '240.0.0.0/4'],
  ])('blocks %s (%s)', (ip, cidr) => {
    expect(blockedRange(ip)).toBe(cidr);
  });

  it.each(['8.8.8.8', '1.1.1.1', '93.184.215.14', '100.63.255.255', '100.128.0.0', '172.15.255.255', '172.32.0.0', '192.0.1.1', '192.169.0.1', '198.17.255.255', '198.20.0.0', '223.255.255.255', '11.0.0.1'])(
    'allows public %s',
    (ip) => {
      expect(blockedRange(ip)).toBeNull();
    },
  );
});

describe('blockedRange (IPv6)', () => {
  it.each([
    ['::', '::/128'],
    ['::1', '::1/128'],
    ['0:0:0:0:0:0:0:1', '::1/128'],
    ['[::1]', '::1/128'],
    ['64:ff9b::a00:1', '64:ff9b::/96'],
    ['100::1', '100::/64'],
    ['2001:db8::1', '2001:db8::/32'],
    ['fc00::1', 'fc00::/7'],
    ['fd00:ec2::254', 'fc00::/7'],
    ['fe80::1', 'fe80::/10'],
    ['fe80::1%eth0', 'fe80::/10'],
    ['febf::1', 'fe80::/10'],
    ['ff02::1', 'ff00::/8'],
  ])('blocks %s (%s)', (ip, cidr) => {
    expect(blockedRange(ip)).toBe(cidr);
  });

  it.each(['2606:4700:4700::1111', '2001:4860:4860::8888', '::2', 'fec0::1', '2001:db9::1', '64:ff9c::1'])('allows public %s', (ip) => {
    expect(blockedRange(ip)).toBeNull();
  });

  it('checks IPv4-mapped IPv6 as IPv4, in any notation', () => {
    expect(blockedRange('::ffff:127.0.0.1')).toMatch(/127\.0\.0\.0\/8/);
    expect(blockedRange('::ffff:7f00:1')).toMatch(/127\.0\.0\.0\/8/);
    expect(blockedRange('[::ffff:169.254.169.254]')).toMatch(/169\.254/);
    expect(blockedRange('0:0:0:0:0:ffff:0a00:0001')).toMatch(/10\.0\.0\.0\/8/);
    expect(blockedRange('::ffff:8.8.8.8')).toBeNull();
  });

  it('returns null for non-IP input', () => {
    expect(blockedRange('example.com')).toBeNull();
    expect(blockedRange('')).toBeNull();
    expect(blockedRange('1.2.3')).toBeNull();
  });
});

describe('hostnames', () => {
  it('normalizes case, brackets and trailing dots', () => {
    expect(normalizeHost('LocalHost.')).toBe('localhost');
    expect(normalizeHost('[0:0::1]')).toBe('::1');
    expect(normalizeHost('Shop.Example.COM')).toBe('shop.example.com');
  });

  it.each(['localhost', 'LOCALHOST.', 'app.localhost', 'printer.local', 'local', 'metadata.google.internal', 'svc.internal'])(
    'blocks %s by name',
    (h) => {
      expect(blockedHostname(h)).not.toBeNull();
    },
  );

  it.each(['example.com', 'localhost.example.com', 'internal.example.com', 'mylocal', 'notinternal'])('does not block %s by name', (h) => {
    expect(blockedHostname(h)).toBeNull();
  });
});

describe('allowlist patterns', () => {
  it('parses and normalizes exact hosts, IPs and wildcards', () => {
    expect(parseHostPattern(' Shop.Fixture.TEST ')).toBe('shop.fixture.test');
    expect(parseHostPattern('*.Fixture.test')).toBe('*.fixture.test');
    expect(parseHostPattern('127.0.0.1')).toBe('127.0.0.1');
    expect(parseHostPattern('127.1')).toBe('127.0.0.1'); // canonical IPv4, like Chromium
    expect(parseHostPattern('[::1]')).toBe('::1');
    expect(parseHostPattern('0:0::1')).toBe('::1');
    expect(parseHostPattern('bücher.example')).toBe('xn--bcher-kva.example');
  });

  it.each(['', '*', '*.', 'http://x.test', 'x.test:8080', 'x.test/path', 'a..b', '*.[::1]', 'foo*.test', 'user@x.test', 'a b'])(
    'rejects %j',
    (raw) => {
      expect(() => parseHostPattern(raw)).toThrow();
    },
  );

  it('wildcards match subdomains only; exact entries match exactly', () => {
    expect(matchesHostPattern('a.fixture.test', '*.fixture.test')).toBe(true);
    expect(matchesHostPattern('A.B.Fixture.Test.', '*.fixture.test')).toBe(true);
    expect(matchesHostPattern('fixture.test', '*.fixture.test')).toBe(false);
    expect(matchesHostPattern('evilfixture.test', '*.fixture.test')).toBe(false);
    expect(matchesHostPattern('127.0.0.1', '127.0.0.1')).toBe(true);
    expect(matchesHostPattern('[::1]', '::1')).toBe(true);
    expect(matchesHostPattern('127.0.0.2', '127.0.0.1')).toBe(false);
  });
});

describe('NetPolicy', () => {
  const policy = (map: Record<string, string[]> = {}, allowHosts: string[] = []) => {
    const r = stubResolver(map);
    return { p: new NetPolicy({ blockPrivate: true, allowHosts, resolver: r.resolve }), calls: r.calls };
  };

  it('blocks IP literals (incl. bracketed IPv6) without a DNS lookup', async () => {
    const { p, calls } = policy();
    for (const url of ['http://10.255.255.1/', 'http://[::1]:3100/', 'https://[fd00::1]/', 'http://[::ffff:10.0.0.1]/', 'http://0x7f.1/', 'http://2130706433/']) {
      expect((await p.checkUrl(url)).action, url).toBe('block');
    }
    expect(await p.checkUrl('http://8.8.8.8/')).toEqual({ action: 'allow' });
    expect(calls).toEqual([]);
  });

  it('blocks a hostname when ANY resolved address is private', async () => {
    const { p } = policy({ 'one.example': [PUBLIC_IP], 'mixed.example': [PUBLIC_IP, '192.168.0.10'], 'v6.example': ['2606:4700::1', 'fe80::2'] });
    expect(await p.checkUrl('https://one.example/x')).toEqual({ action: 'allow' });
    expect(await p.checkUrl('https://mixed.example/x')).toMatchObject({ action: 'block', host: 'mixed.example', reason: expect.stringMatching(/192\.168\.0\.10/) });
    expect(await p.checkUrl('wss://v6.example/socket')).toMatchObject({ action: 'block' });
  });

  it('blocks local-only names without resolving them', async () => {
    const { p, calls } = policy();
    for (const url of ['http://localhost:9000/', 'http://api.localhost/', 'http://nas.local/', 'http://metadata.google.internal/']) {
      expect((await p.checkUrl(url)).action, url).toBe('block');
    }
    expect(calls).toEqual([]);
  });

  it('reports DNS failures as unresolved (not cached)', async () => {
    const { p, calls } = policy({ 'gone.example': [] });
    expect(await p.checkUrl('http://gone.example/')).toMatchObject({ action: 'unresolved', reason: expect.stringMatching(/ENOTFOUND/) });
    await p.checkUrl('http://gone.example/');
    expect(calls).toEqual(['gone.example', 'gone.example']);
  });

  it('allows data:/blob:, blocks other schemes', async () => {
    const { p } = policy();
    expect((await p.checkUrl('data:image/png;base64,AA==')).action).toBe('allow');
    expect((await p.checkUrl('blob:https://shop.example/uuid')).action).toBe('allow');
    expect((await p.checkUrl('file:///etc/passwd')).action).toBe('block');
    expect((await p.checkUrl('ftp://example.com/')).action).toBe('block');
  });

  it('lets allowlisted hosts (exact and wildcard) bypass the policy', async () => {
    const { p, calls } = policy({ 'shop.fixture.test': ['172.18.0.5'] }, ['*.fixture.test', '127.0.0.1', '[::1]']);
    expect((await p.checkUrl('http://shop.fixture.test/')).action).toBe('allow');
    expect((await p.checkUrl('http://127.0.0.1:8080/')).action).toBe('allow');
    expect((await p.checkUrl('http://[::1]/')).action).toBe('allow');
    expect((await p.checkUrl('http://127.0.0.2/')).action).toBe('block');
    expect((await p.checkUrl('http://fixture.test/')).action).toBe('allow'); // not allowlisted, but resolves public
    expect(calls).toEqual(['fixture.test']);
  });

  it('allows everything when disabled', async () => {
    const p = new NetPolicy({ blockPrivate: false });
    expect(p.enabled).toBe(false);
    expect((await p.checkUrl('http://127.0.0.1/')).action).toBe('allow');
    expect((await p.checkUrl('http://localhost/')).action).toBe('allow');
  });

  it('caches lookups for the TTL and de-duplicates concurrent ones', async () => {
    let now = 1_000;
    const r = stubResolver({ 'shop.example': [PUBLIC_IP] });
    const p = new NetPolicy({ blockPrivate: true, resolver: r.resolve, cacheTtlMs: 30_000, now: () => now });
    await Promise.all([p.checkUrl('https://shop.example/a'), p.checkUrl('https://shop.example/b')]);
    now += 29_000;
    await p.checkUrl('https://SHOP.example./c');
    expect(r.calls).toEqual(['shop.example']);
    now += 2_000;
    await p.checkUrl('https://shop.example/d');
    expect(r.calls).toEqual(['shop.example', 'shop.example']);
  });

  it('bounds the cache size', async () => {
    const r = stubResolver();
    const p = new NetPolicy({ blockPrivate: true, resolver: r.resolve, maxCacheEntries: 2 });
    for (const h of ['a.example', 'b.example', 'c.example', 'a.example']) await p.checkUrl(`https://${h}/`);
    expect(r.calls).toEqual(['a.example', 'b.example', 'c.example', 'a.example']);
  });

  it('uses the system resolver by default (verbatim, all addresses)', async () => {
    const p = new NetPolicy({ blockPrivate: true });
    // `localhost` is blocked by name; a name that the system resolves to loopback is blocked by address.
    expect((await p.checkUrl('http://localhost/')).action).toBe('block');
    const d = await p.checkHost('ip6-localhost');
    expect(['block', 'unresolved']).toContain(d.action);
  });
});
